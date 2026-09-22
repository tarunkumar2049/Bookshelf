const app = {
    csrfToken: '',
    currentUser: null,
    _basePath: null,

    get basePath() {
        if (this._basePath !== null) {
            return this._basePath;
        }
        const path = window.location.pathname.replace(/\\/g, '/');
        const adminIndex = path.lastIndexOf('/admin/');
        if (adminIndex !== -1) {
            this._basePath = path.slice(0, adminIndex);
        } else {
            this._basePath = path.slice(0, path.lastIndexOf('/'));
        }
        return this._basePath;
    },

    api(path) {
        return `${this.basePath}/api/${path}`;
    },

    asset(path) {
        return `${this.basePath}/${path.replace(/^\/+/, '')}`;
    },

    page() {
        return window.location.pathname.split('/').pop() || 'index.html';
    },
};


function preferredTheme() {
    let saved = null;
    try {
        saved = localStorage.getItem('bookshelf_theme');
    } catch (error) {
        saved = null;
    }
    if (saved === 'dark' || saved === 'light') {
        return saved;
    }
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}

function applyTheme(theme) {
    document.documentElement.dataset.theme = theme;
    document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
        const isDark = theme === 'dark';
        button.setAttribute('aria-pressed', isDark ? 'true' : 'false');
        button.setAttribute('aria-label', isDark ? 'Switch to light mode' : 'Switch to dark mode');
        const label = button.querySelector('[data-theme-label]');
        if (label) {
            label.textContent = isDark ? 'Dark' : 'Light';
        }
    });
}

function toggleTheme() {
    const nextTheme = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
    try {
        localStorage.setItem('bookshelf_theme', nextTheme);
    } catch (error) {
        // Theme still changes for this page even if browser storage is blocked.
    }
    applyTheme(nextTheme);
    document.querySelectorAll('.panel-row-value').forEach((el) => {
        el.textContent = nextTheme === 'dark' ? 'Dark' : 'Light';
    });
}

function initThemeToggle() {
    applyTheme(preferredTheme());
}

function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function sanitizeUrl(url) {
    const str = String(url || '').trim();
    if (/^(javascript|data|vbscript):/i.test(str)) {
        return '';
    }
    return str;
}

function nl2br(value) {
    return escapeHtml(value).replace(/\n/g, '<br>');
}

async function apiFetch(path, options = {}) {
    const response = await fetch(app.api(path), {
        credentials: 'same-origin',
        ...options,
        headers: {
            ...(options.body instanceof FormData ? {} : { 'Content-Type': 'application/json' }),
            ...(options.headers || {}),
        },
    });
    const data = await response.json().catch(() => ({ ok: false, error: 'Invalid server response.' }));
    if (!response.ok || !data.ok) {
        throw new Error(data.error || 'Request failed.');
    }
    return data;
}

function queryParams() {
    return new URLSearchParams(window.location.search);
}

function showNotice(target, message, type = 'success') {
    if (!target) {
        return;
    }
    target.innerHTML = `<div class="flash flash-${type}">${escapeHtml(message)}</div>`;
}

function usernameFromEmail(email) {
    return String(email || 'reader').split('@')[0];
}

function coverMarkup(book) {
    if (book.cover_image) {
        const safeSrc = sanitizeUrl(app.asset(book.cover_image));
        if (!safeSrc) {
            return `<div class="cover-placeholder">${escapeHtml(String(book.title || 'B').slice(0, 1))}</div>`;
        }
        return `<img src="${escapeHtml(safeSrc)}" alt="${escapeHtml(book.title)} cover" loading="lazy" decoding="async">`;
    }
    return `<div class="cover-placeholder">${escapeHtml(String(book.title || 'B').slice(0, 1))}</div>`;
}

function bookCardHtml(book, extra = '') {
    const id = Number(book.id);
    if (!id || isNaN(id)) {
        return '';
    }
    const pdfBadge = book.pdf_file ? '<span class="book-badge">PDF</span>' : '';
    const genres = Array.isArray(book.genres) && book.genres.length
        ? `<p class="card-genres">${escapeHtml(book.genres.slice(0, 2).map((genre) => genre.name).join(' / '))}</p>`
        : '';
    return `
        <article class="book-card">
            <a href="book.html?id=${id}">
                <div class="cover-frame">${coverMarkup(book)}${pdfBadge}</div>
                <div class="book-card-body">
                    <h3>${escapeHtml(book.title)}</h3>
                    <p>${escapeHtml(book.author || '')}</p>
                    ${genres}
                    ${extra}
                </div>
            </a>
        </article>
    `;
}

function genrePillsHtml(genres) {
    if (!Array.isArray(genres) || !genres.length) {
        return '<span class="muted">No genre selected</span>';
    }

    return `<div class="genre-chip-list">${genres.map((genre) => `<a class="genre-pill" href="index.html?genre_id=${Number(genre.id)}&genre_name=${encodeURIComponent(genre.name)}">${escapeHtml(genre.name)}</a>`).join('')}</div>`;
}

function renderShelf(container, books, emptyMessage = 'No books to show yet.') {
    if (!container) {
        return;
    }

    if (!books || !books.length) {
        container.innerHTML = `<p class="muted">${escapeHtml(emptyMessage)}</p>`;
        return;
    }

    container.innerHTML = books.map((book) => bookCardHtml(book)).join('');
}

function continueCardHtml(item) {
    const isChapter = Number(item.chapter_id) > 0;
    const url = isChapter
        ? `reader.html?chapter_id=${Number(item.chapter_id)}`
        : `book.html?id=${Number(item.id)}`;
    const progress = isChapter
        ? `Ch. ${escapeHtml(item.chapter_number)} &middot; ${escapeHtml(new Date(item.last_read_at).toLocaleDateString())}`
        : 'PDF book';
    return `
        <article class="book-card">
            <a href="${url}">
                <div class="cover-frame">${coverMarkup(item)}</div>
                <div class="book-card-body">
                    <h3>${escapeHtml(item.title)}</h3>
                    <p>${escapeHtml(item.author || '')}</p>
                    <p class="card-progress">${progress}</p>
                </div>
            </a>
        </article>
    `;
}

function populateGenreSearch(genres) {
    const panel = document.querySelector('#genreSearchPanel');
    const list = document.querySelector('#genreList');
    const input = document.querySelector('#q');
    if (!panel || !list || !input || list.dataset.ready === 'true') {
        return;
    }

    list.dataset.ready = 'true';
    list.innerHTML = genres.length
        ? genres.map((genre) => `<a class="genre-chip" href="index.html?genre_id=${Number(genre.id)}&genre_name=${encodeURIComponent(genre.name)}">${escapeHtml(genre.name)}</a>`).join('')
        : '<span class="muted">No genres created yet.</span>';

    input.addEventListener('input', () => {
        panel.hidden = input.value.trim() !== '';
    });

    input.addEventListener('focus', () => {
        panel.hidden = input.value.trim() !== '';
    });

    document.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target : null;
        if (target && (target.closest('#searchOverlay') || target.closest('.search-toggle'))) {
            return;
        }
        panel.hidden = true;
    });
}

async function initGenreSearch() {
    const panel = document.querySelector('#genreSearchPanel');
    const list = document.querySelector('#genreList');
    if (!panel || !list || list.dataset.ready === 'true') {
        return;
    }
    try {
        const data = await apiFetch('books.php?q=&page=1&per_page=1');
        populateGenreSearch(data.genres || []);
    } catch (error) {
        // Genre chips stay hidden if the lookup fails.
    }
}

function renderHeroSlideshow(books) {
    const slideshow = document.querySelector('#heroSlideshow');
    const dots = document.querySelector('#heroDots');

    if (!slideshow) {
        return;
    }

    const hero = slideshow.closest('.bookshelf-hero');

    // Only use the books supplied by the Popular Books API
    const slides = Array.isArray(books)
        ? books.filter(Boolean).slice(0, 5)
        : [];

    if (!slides.length) {
        slideshow.innerHTML = '';
        if (dots) {
            dots.innerHTML = '';
        }
        hero?.classList.add('empty');
        return;
    }

    hero?.classList.remove('empty');

    slideshow.innerHTML = slides.map((book, index) => {
        const coverUrl = book.cover_image
            ? app.asset(book.cover_image)
            : '';

        const description = book.description ||
            'Open this book to explore the full story and available reading options.';

        const readUrl = `book.html?id=${Number(book.id)}`;

        return `
            <a
                class="hero-slide ${index === 0 ? 'active' : ''}"
                href="${readUrl}"
                style="--hero-cover: url('${escapeHtml(
                    coverUrl
                ).replace(/'/g, '%27').replace(/\)/g, '%29')}')"
            >

                <div class="hero-copy">
                    <span>Popular now</span>

                    <h2>${escapeHtml(book.title || '')}</h2>

                    <p class="hero-author">
                        ${escapeHtml(book.author || '')}
                    </p>

                    <p class="hero-description">
                        ${escapeHtml(description)}
                    </p>

                    <span class="hero-read-more">
                        Read Now <b>→</b>
                    </span>
                </div>

                <div class="hero-covers" aria-hidden="true">
                    <div class="cover-frame">
                        ${coverMarkup(book)}
                    </div>
                </div>

            </a>
        `;
    }).join('');

    /* -----------------------------------------
       SLIDER DOTS
       ----------------------------------------- */

    if (dots) {
        dots.innerHTML = slides.map((_, index) => `
            <button
                type="button"
                class="${index === 0 ? 'active' : ''}"
                aria-label="Show slide ${index + 1}"
                data-hero-dot="${index}"
            ></button>
        `).join('');
    }

    let current = 0;

    if (window._heroSlideshowTimer) {
        window.clearInterval(window._heroSlideshowTimer);
        window._heroSlideshowTimer = null;
    }

    /* -----------------------------------------
       SHOW SLIDE
       ----------------------------------------- */

    const showSlide = (nextIndex) => {
        const items = slideshow.querySelectorAll('.hero-slide');

        if (!items.length) {
            return;
        }

        current = (nextIndex + items.length) % items.length;

        items.forEach((item, index) => {
            item.classList.toggle(
                'active',
                index === current
            );
        });

        dots?.querySelectorAll('[data-hero-dot]').forEach(
            (dot, index) => {
                dot.classList.toggle(
                    'active',
                    index === current
                );
            }
        );
    };

    /* -----------------------------------------
       AUTO SLIDE
       ----------------------------------------- */

    const startTimer = () => {
        window.clearInterval(window._heroSlideshowTimer);

        if (slides.length <= 1) {
            return;
        }

        window._heroSlideshowTimer = window.setInterval(() => {
            showSlide(current + 1);
        }, 4200);
    };

    /* -----------------------------------------
       DOT CONTROLS
       ----------------------------------------- */

    dots?.querySelectorAll('[data-hero-dot]').forEach((dot) => {
        dot.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();

            showSlide(
                Number(dot.dataset.heroDot)
            );

            startTimer();
        });
    });

    /* -----------------------------------------
       PAUSE ON HOVER
       ----------------------------------------- */

    hero?.addEventListener('mouseenter', () => {
        window.clearInterval(window._heroSlideshowTimer);
    });

    hero?.addEventListener('mouseleave', () => {
        startTimer();
    });

    startTimer();
}

async function initReaderSession() {
    try {
        const data = await apiFetch('user/session.php');
        app.currentUser = data.user;
        app.csrfToken = data.csrf_token;
    } catch (error) {
        app.currentUser = null;
    }
    updateHeaderProfile();
}

function updateHeaderProfile() {
    const toggle = document.querySelector('#userPanelToggle');
    if (!toggle) {
        return;
    }
    if (!toggle.dataset.defaultIcon) {
        toggle.dataset.defaultIcon = toggle.innerHTML;
    }
    const user = app.currentUser;
    toggle.classList.remove('has-avatar', 'has-initial');
    const existingImg = toggle.querySelector('img');
    if (existingImg) {
        existingImg.remove();
    }
    const existingInitial = toggle.querySelector('.header-avatar-initial');
    if (existingInitial) {
        existingInitial.remove();
    }
    toggle.querySelector('svg')?.removeAttribute('hidden');

    if (!user) {
        toggle.innerHTML = toggle.dataset.defaultIcon;
        toggle.setAttribute('aria-label', 'Open menu');
        return;
    }

    toggle.setAttribute('aria-label', `Open menu for ${usernameFromEmail(user.email)}`);
    if (user.profile_image) {
        const svg = toggle.querySelector('svg');
        if (svg) {
            svg.setAttribute('hidden', 'hidden');
        }
        const img = document.createElement('img');
        img.src = app.asset(user.profile_image);
        img.alt = '';
        img.className = 'header-avatar-img';
        img.loading = 'lazy';
        img.decoding = 'async';
        img.addEventListener('error', () => {
            img.remove();
            svg?.removeAttribute('hidden');
        });
        toggle.appendChild(img);
        toggle.classList.add('has-avatar');
        return;
    }

    const svg = toggle.querySelector('svg');
    if (svg) {
        svg.setAttribute('hidden', 'hidden');
    }
    const initial = document.createElement('span');
    initial.className = 'header-avatar-initial';
    initial.setAttribute('aria-hidden', 'true');
    initial.textContent = usernameFromEmail(user.email).slice(0, 1).toUpperCase() || 'U';
    toggle.appendChild(initial);
    toggle.classList.add('has-initial');
}

async function loadHome() {
    const grid = document.querySelector('#bookGrid');
    if (!grid) {
        return;
    }

    const params = queryParams();
    const search = params.get('q') || '';
    const page = Number(params.get('page') || 1);
    const genreId = Number(params.get('genre_id') || 0);
    const genreName = params.get('genre_name') || '';
    const searchInput = document.querySelector('#q');
    const browseTitle = document.querySelector('#browseTitle');
    const browseSubtitle = document.querySelector('#browseSubtitle');
    const clearSearch = document.querySelector('#clearSearch');
    const emptyState = document.querySelector('#emptyState');
    const pagination = document.querySelector('#pagination');
    const popularShelf = document.querySelector('#popularShelf');
    const recommendedShelf = document.querySelector('#recommendedShelf');
    const continueShelf = document.querySelector('#continueShelf');
    const continueSection = document.querySelector('#continueSection');
    const introCopy = document.querySelector('.intro-copy');
    const heroSection = document.querySelector('.bookshelf-hero');
    const heroDots = document.querySelector('#heroDots');
    const welcomeBlock = document.querySelector('.welcome-block');

    searchInput.value = search;
    browseTitle.textContent = search ? `Results for "${search}"` : (genreId ? `${genreName || 'Genre'} books` : 'Recently added');
    browseSubtitle.textContent = search || genreId ? 'Filtered books by title or author' : 'Newest books in the library';
    clearSearch.hidden = !search && !genreId;
    if (introCopy) {
        introCopy.hidden = Boolean(search || genreId);
    }
    if (heroSection) {
        heroSection.hidden = Boolean(search || genreId);
    }
    if (heroDots) {
        heroDots.hidden = Boolean(search || genreId);
    }
    if (welcomeBlock) {
        welcomeBlock.hidden = Boolean(search || genreId);
    }
    if (popularShelf) {
        popularShelf.closest('.book-shelf')?.toggleAttribute('hidden', Boolean(search || genreId));
    }
    if (recommendedShelf) {
        recommendedShelf.closest('.book-shelf')?.toggleAttribute('hidden', Boolean(search || genreId));
    }
    if (continueSection) {
        continueSection.hidden = true;
    }
    const customSections = document.querySelector('#customSections');
    if (customSections) {
        customSections.hidden = true;
    }
    grid.innerHTML = `
        <div class="skeleton-card skeleton"><div class="skeleton skeleton-cover"></div><div class="skeleton skeleton-title"></div><div class="skeleton skeleton-subtitle"></div></div>
        <div class="skeleton-card skeleton"><div class="skeleton skeleton-cover"></div><div class="skeleton skeleton-title"></div><div class="skeleton skeleton-subtitle"></div></div>
        <div class="skeleton-card skeleton"><div class="skeleton skeleton-cover"></div><div class="skeleton skeleton-title"></div><div class="skeleton skeleton-subtitle"></div></div>
        <div class="skeleton-card skeleton"><div class="skeleton skeleton-cover"></div><div class="skeleton skeleton-title"></div><div class="skeleton skeleton-subtitle"></div></div>
        <div class="skeleton-card skeleton"><div class="skeleton skeleton-cover"></div><div class="skeleton skeleton-title"></div><div class="skeleton skeleton-subtitle"></div></div>
    `;
    renderShelf(popularShelf, [], 'Loading popular books...');
    renderShelf(recommendedShelf, [], 'Loading recommendations...');

    try {
        const genreQuery = genreId ? `&genre_id=${genreId}` : '';
        const recentLimit = !search && !genreId
    ? `&per_page=${window.innerWidth <= 768 ? 4 : 5}`
    : '';

const data = await apiFetch(
    `books.php?q=${encodeURIComponent(search)}&page=${page}${genreQuery}${recentLimit}`
);
        const [popularData, recommendedData, profileData, sectionsData] = await Promise.all([
            apiFetch('books.php?mode=popular'),
            apiFetch('books.php?mode=recommended'),
            (app.currentUser && !search && !genreId) ? apiFetch('user/profile.php').catch(() => null) : Promise.resolve(null),
            (!search && !genreId) ? apiFetch('sections.php').catch(() => null) : Promise.resolve(null),
        ]);

        populateGenreSearch(data.genres || []);
        renderHeroSlideshow(popularData.books);
        grid.innerHTML = '';
        emptyState.hidden = data.books.length > 0;
        renderShelf(popularShelf, popularData.books, 'No popular books selected yet.');
        renderShelf(recommendedShelf, recommendedData.books, 'Read a few books to improve recommendations.');

        if (continueShelf && continueSection && profileData) {
            try {
                const recent = (profileData.history || []).slice(0, 8);
                if (recent.length) {
                    continueShelf.innerHTML = recent.map((item) => continueCardHtml(item)).join('');
                    continueSection.hidden = false;
                }
            } catch (error) {
                // Continue reading stays hidden when history is unavailable.
            }
        }

        if (customSections && !search && !genreId) {
            try {
                const sections = (sectionsData && sectionsData.sections) || [];
                customSections.innerHTML = sections.map((section) => {
                    const sectionId = Number(section.id);
                    return `
                    <section class="book-shelf" aria-label="${escapeHtml(section.title)}">
                        <div class="section-heading">
                            <div>
                                <h2>${escapeHtml(section.title)}</h2>
                                <p>${escapeHtml(section.subtitle || 'Curated collection')}</p>
                            </div>
                            <div class="shelf-controls" aria-label="${escapeHtml(section.title)} controls">
                                <button type="button" class="shelf-scroll-button" data-scroll-shelf="customShelf-${sectionId}" data-direction="-1" aria-label="Scroll left">&lt;</button>
                                <button type="button" class="shelf-scroll-button" data-scroll-shelf="customShelf-${sectionId}" data-direction="1" aria-label="Scroll right">&gt;</button>
                            </div>
                        </div>
                        <div class="shelf-track" id="customShelf-${sectionId}">${(section.books || []).map((book) => bookCardHtml(book)).join('')}</div>
                    </section>`;
                }).join('');
                customSections.hidden = sections.length === 0;
            } catch (error) {
                customSections.innerHTML = '';
            }
        }

        data.books.forEach((book) => {
            grid.insertAdjacentHTML('beforeend', bookCardHtml(book));
        });

        pagination.innerHTML = '';
        if (data.pages > 1) {
            const current = Number(data.page) || 1;
            const total = Number(data.pages) || 1;
            const pageUrl = (i) => `index.html?q=${encodeURIComponent(search)}&page=${i}${genreId ? `&genre_id=${genreId}&genre_name=${encodeURIComponent(genreName)}` : ''}`;

            const getPageList = (cur, tot) => {
                if (tot <= 7) {
                    return Array.from({ length: tot }, (_, idx) => idx + 1);
                }
                if (cur <= 4) {
                    return [1, 2, 3, 4, 5, '...', tot];
                }
                if (cur >= tot - 3) {
                    return [1, '...', tot - 4, tot - 3, tot - 2, tot - 1, tot];
                }
                return [1, '...', cur - 1, cur, cur + 1, '...', tot];
            };

            const prev = document.createElement('a');
            prev.href = pageUrl(Math.max(1, current - 1));
            prev.className = `page-arrow${current <= 1 ? ' disabled' : ''}`;
            prev.setAttribute('aria-label', 'Previous page');
            prev.innerHTML = '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"></polyline></svg>';
            if (current <= 1) {
                prev.setAttribute('aria-disabled', 'true');
                prev.addEventListener('click', (e) => e.preventDefault());
            }
            pagination.appendChild(prev);

            getPageList(current, total).forEach((item) => {
                if (item === '...') {
                    const dots = document.createElement('span');
                    dots.className = 'page-ellipsis';
                    dots.textContent = '...';
                    pagination.appendChild(dots);
                    return;
                }
                const link = document.createElement('a');
                link.href = pageUrl(item);
                link.textContent = item;
                link.className = 'page-num';
                if (item === current) {
                    link.classList.add('active');
                    link.setAttribute('aria-current', 'page');
                }
                pagination.appendChild(link);
            });

            const next = document.createElement('a');
            next.href = pageUrl(Math.min(total, current + 1));
            next.className = `page-arrow${current >= total ? ' disabled' : ''}`;
            next.setAttribute('aria-label', 'Next page');
            next.innerHTML = '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>';
            if (current >= total) {
                next.setAttribute('aria-disabled', 'true');
                next.addEventListener('click', (e) => e.preventDefault());
            }
            pagination.appendChild(next);
        }
    } catch (error) {
        grid.innerHTML = '';
        emptyState.hidden = false;
        emptyState.innerHTML = `<p>${escapeHtml(error.message)}</p>`;
    }
}

function initHomeSearch() {
    const form = document.querySelector('#searchForm');
    if (!form) {
        return;
    }
    form.addEventListener('submit', (event) => {
        event.preventDefault();
        const q = new FormData(form).get('q') || '';
        window.location.href = `index.html?q=${encodeURIComponent(String(q).trim())}`;
    });
}

function initShelfScrolling() {
    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-scroll-shelf]');
        if (!button) {
            return;
        }

        const shelf = document.querySelector(`#${button.dataset.scrollShelf}`);
        if (!shelf) {
            return;
        }

        const direction = Number(button.dataset.direction || 1);
        shelf.scrollBy({ left: direction * Math.max(260, shelf.clientWidth * 0.82), behavior: 'smooth' });
    });
}

async function loadBookDetail() {
    const detail = document.querySelector('#bookDetail');
    if (!detail) {
        return;
    }

    const id = Number(queryParams().get('id') || 0);
    const list = document.querySelector('#chapterList');
    const count = document.querySelector('#chapterCount');
    const contentTitle = document.querySelector('#bookContentTitle');
    const contentSection = document.querySelector('#bookContentSection');
    const recommendations = document.querySelector('#bookRecommendations');
    detail.innerHTML = '<p class="muted">Loading book...</p>';
    if (list) {
        list.innerHTML = '';
    }
    if (recommendations) {
        recommendations.innerHTML = '';
    }

    if (!id || isNaN(id)) {
        detail.innerHTML = '<section class="empty-state"><h1>Book not found</h1><p>Missing book id.</p></section>';
        if (list) {
            list.innerHTML = '';
        }
        if (count) {
            count.textContent = '';
        }
        if (contentTitle) {
            contentTitle.textContent = 'Chapters';
        }
        if (contentSection) {
            contentSection.hidden = true;
        }
        renderShelf(recommendations, [], 'No similar books yet.');
        return;
    }

    try {
        const data = await apiFetch(`book.php?id=${id}`);
        const book = data.book || {};
        const bookId = Number(book.id || id);
        const chapters = Array.isArray(data.chapters) ? data.chapters : [];
        const author = String(book.author || '').trim() || 'Unknown author';
        const description = String(book.description || '').trim();
        document.title = `${book.title || 'Book'} - Bookshelf Reader`;
        detail.innerHTML = `
            <div class="detail-cover cover-frame">${coverMarkup(book)}</div>
            <div class="detail-copy">
                <p class="eyebrow">${escapeHtml(author)}</p>
                <h1>${escapeHtml(book.title || 'Untitled')}</h1>
                <p>${description ? nl2br(description) : '<span class="muted">No description available.</span>'}</p>
                <div class="detail-genres">${genrePillsHtml(data.genres || book.genres || [])}</div>
                <div class="detail-actions" id="bookActions">
                    ${book.pdf_file ? `<a class="button-link" href="reader.html?book_id=${bookId}">Read PDF</a>` : ''}
                    <button type="button" class="button-link secondary" data-wishlist-toggle data-book-id="${bookId}">Add to wishlist</button>
                </div>
            </div>
        `;
        renderShelf(recommendations, data.recommended || [], 'No similar books yet.');

        await refreshWishlistButton(bookId);

        const hasPdf = String(book.pdf_file || '').trim() !== '';
        const hasPageInfo = chapters.some((chapter) => chapter.page_count !== undefined && chapter.page_count !== null);
        const readableChapters = hasPageInfo
            ? chapters.filter((chapter) => Number(chapter.page_count) > 0)
            : chapters;

        if (hasPdf) {
            if (contentTitle) {
                contentTitle.textContent = 'PDF Book';
            }
            if (count) {
                count.textContent = 'Available';
            }
            if (contentSection) {
                contentSection.hidden = false;
            }
            list.innerHTML = `
                <section class="pdf-panel">
                    <div>
                        <p class="eyebrow">PDF Reader</p>
                        <h2>${escapeHtml(book.title || 'Untitled')}</h2>
                        <p class="muted">Read the uploaded PDF in the built-in reader.</p>
                        <div class="detail-actions">
                            <a class="button-link" href="reader.html?book_id=${bookId}">Read now</a>
                        </div>
                    </div>
                </section>
            `;
            return;
        }

        if (!readableChapters.length) {
            if (list) {
                list.innerHTML = '';
            }
            if (count) {
                count.textContent = '';
            }
            if (contentSection) {
                contentSection.hidden = true;
            }
            return;
        }

        if (contentTitle) {
            contentTitle.textContent = 'Chapters';
        }
        if (count) {
            count.textContent = `${readableChapters.length} total`;
        }
        if (contentSection) {
            contentSection.hidden = false;
        }

        list.innerHTML = readableChapters.map((chapter) => {
            const chapterDate = chapter.created_at ? new Date(chapter.created_at) : null;
            const dateLabel = chapterDate && !isNaN(chapterDate) ? chapterDate.toLocaleDateString() : '';
            return `
            <a class="chapter-row" href="reader.html?chapter_id=${Number(chapter.id)}">
                <span>Chapter ${escapeHtml(chapter.chapter_number ?? '')}</span>
                <strong>${escapeHtml(chapter.title || `Chapter ${chapter.chapter_number ?? ''}`)}</strong>
                <small>${escapeHtml(dateLabel)}</small>
            </a>
        `;
        }).join('');
    } catch (error) {
        detail.innerHTML = `<section class="empty-state"><h1>Book not found</h1><p>${escapeHtml(error.message)}</p></section>`;
        if (list) {
            list.innerHTML = '';
        }
        if (count) {
            count.textContent = '';
        }
        if (contentTitle) {
            contentTitle.textContent = 'Chapters';
        }
        if (contentSection) {
            contentSection.hidden = true;
        }
        renderShelf(recommendations, [], 'No similar books yet.');
    }
}

async function refreshWishlistButton(bookId) {
    const button = document.querySelector('[data-wishlist-toggle]');
    if (!button) {
        return;
    }

    if (!app.currentUser) {
        button.textContent = 'Login to wishlist';
        button.dataset.requiresLogin = 'true';
        return;
    }

    try {
        const data = await apiFetch(`user/wishlist.php?book_id=${bookId}`);
        button.textContent = data.in_wishlist ? 'Remove from wishlist' : 'Add to wishlist';
        button.dataset.inWishlist = data.in_wishlist ? 'true' : 'false';
    } catch (error) {
        button.textContent = 'Add to wishlist';
    }
}

function chapterButtons(previousId, nextId, shortLabels = false) {
    const buttons = [];
    if (previousId) {
        buttons.push(`<a class="button-link secondary" href="reader.html?chapter_id=${Number(previousId)}">${shortLabels ? 'Previous' : 'Previous chapter'}</a>`);
    }
    if (nextId) {
        buttons.push(`<a class="button-link" href="reader.html?chapter_id=${Number(nextId)}">${shortLabels ? 'Next' : 'Next chapter'}</a>`);
    }
    return buttons.join('');
}

function getReaderSettings() {
    const defaults = { mode: 'scroll', theme: 'dark', fit: 'width' };
    try {
        const saved = JSON.parse(localStorage.getItem('bookshelf_reader_settings') || '{}');
        return {
            mode: saved.mode === 'flip' ? 'flip' : 'scroll',
            theme: ['dark', 'light', 'sepia'].includes(saved.theme) ? saved.theme : 'dark',
            fit: ['width', 'height', 'screen'].includes(saved.fit) ? saved.fit : 'width',
        };
    } catch (error) {
        return defaults;
    }
}

function saveReaderSettings(settings) {
    try {
        localStorage.setItem('bookshelf_reader_settings', JSON.stringify(settings));
    } catch (error) {
        // Settings still apply for this page even if storage is blocked.
    }
}

function applyReaderSettings() {
    const settings = getReaderSettings();
    const stream = document.querySelector('#readerStream');
    if (stream) {
        stream.dataset.readerMode = settings.mode;
        stream.dataset.readerTheme = settings.theme;
        stream.dataset.readerFit = settings.fit;
    }
    document.querySelectorAll('[data-reader-mode]').forEach((button) => {
        button.classList.toggle('active', button.dataset.readerMode === settings.mode);
    });
    document.querySelectorAll('[data-reader-theme]').forEach((button) => {
        button.classList.toggle('active', button.dataset.readerTheme === settings.theme);
    });
    document.querySelectorAll('[data-reader-fit]').forEach((button) => {
        button.classList.toggle('active', button.dataset.readerFit === settings.fit);
    });
    const flipNav = document.querySelector('#readerFlipNav');
    if (flipNav) {
        flipNav.hidden = settings.mode !== 'flip';
    }
    document.body.classList.toggle('reader-flip', settings.mode === 'flip' && !!document.querySelector('#readerStream'));
    return settings;
}

function updateReaderProgress(pageNumber, total) {
    const progress = document.querySelector('#readerProgress');
    if (progress && total > 0) {
        progress.textContent = `Page ${pageNumber} of ${total}`;
    }
    const counter = document.querySelector('#flipCounter');
    if (counter && total > 0) {
        counter.textContent = `Page ${pageNumber} of ${total}`;
    }
}

function currentReaderPage(stream) {
    const items = [...stream.querySelectorAll('[data-page-number]')];
    if (!items.length) {
        return null;
    }
    if (getReaderSettings().mode === 'flip') {
        const middle = stream.scrollLeft + stream.clientWidth / 2;
        let best = items[0];
        let bestDistance = Infinity;
        items.forEach((element) => {
            const center = element.offsetLeft + element.offsetWidth / 2;
            const distance = Math.abs(center - middle);
            if (distance < bestDistance) {
                bestDistance = distance;
                best = element;
            }
        });
        return Number(best.dataset.pageNumber);
    }
    const line = window.scrollY + window.innerHeight * 0.35;
    let current = Number(items[0].dataset.pageNumber);
    items.forEach((element) => {
        const top = element.getBoundingClientRect().top + window.scrollY;
        if (top <= line) {
            current = Number(element.dataset.pageNumber);
        }
    });
    return current;
}

function scrollReaderToPage(stream, pageNumber, smooth = false) {
    const target = stream.querySelector(`[data-page-number="${pageNumber}"]`);
    if (!target) {
        return;
    }
    if (getReaderSettings().mode === 'flip') {
        stream.scrollTo({ left: target.offsetLeft, behavior: smooth ? 'smooth' : 'auto' });
        return;
    }
    const top = target.getBoundingClientRect().top + window.scrollY - 140;
    window.scrollTo({ top: Math.max(0, top), behavior: smooth ? 'smooth' : 'auto' });
}

function initReaderControls(target, totalPages) {
    if (window._readerControlsWired) {
        return;
    }
    window._readerControlsWired = true;

    const chapterId = Number(target?.chapterId || 0);
    const bookId = Number(target?.bookId || 0);
    const stream = document.querySelector('#readerStream');
    let lastSavedPage = 0;
    let saveTimer = null;

    const trackProgress = () => {
        if (!app.currentUser || (!chapterId && !bookId) || !totalPages) {
            return;
        }
        const current = currentReaderPage(stream);
        if (!current || current === lastSavedPage) {
            return;
        }
        updateReaderProgress(current, totalPages);
        window.clearTimeout(saveTimer);
        saveTimer = window.setTimeout(() => {
            lastSavedPage = current;
            apiFetch('user/history.php', {
                method: 'POST',
                body: JSON.stringify({
                    ...(chapterId ? { chapter_id: chapterId } : { book_id: bookId }),
                    page_number: current,
                    csrf_token: app.csrfToken,
                }),
            }).catch(() => {});
        }, 800);
    };

    let ticking = false;
    const onScroll = () => {
        const current = currentReaderPage(stream);
        if (current) {
            updateReaderProgress(current, totalPages);
        }
        if (ticking) {
            return;
        }
        ticking = true;
        window.requestAnimationFrame(() => {
            ticking = false;
            trackProgress();
        });
    };
    window.addEventListener('scroll', onScroll, { passive: true });
    stream.addEventListener('scroll', onScroll, { passive: true });

    document.querySelector('#readerSettingsToggle')?.addEventListener('click', () => {
        const panel = document.querySelector('#readerSettingsPanel');
        const toggle = document.querySelector('#readerSettingsToggle');
        if (!panel) {
            return;
        }
        panel.hidden = !panel.hidden;
        toggle?.setAttribute('aria-expanded', String(!panel.hidden));
    });

    const closeSettings = () => {
        const panel = document.querySelector('#readerSettingsPanel');
        const toggle = document.querySelector('#readerSettingsToggle');
        if (panel && !panel.hidden) {
            panel.hidden = true;
            toggle?.setAttribute('aria-expanded', 'false');
        }
    };
    document.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target : null;
        if (!target || target.closest('#readerSettingsPanel') || target.closest('#readerSettingsToggle')) {
            return;
        }
        closeSettings();
    });
    window.addEventListener('scroll', closeSettings, { passive: true });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeSettings();
        }
    });

    document.querySelectorAll('[data-reader-mode]').forEach((button) => {
        button.addEventListener('click', () => {
            const settings = getReaderSettings();
            const current = currentReaderPage(stream) || 1;
            settings.mode = button.dataset.readerMode === 'flip' ? 'flip' : 'scroll';
            saveReaderSettings(settings);
            applyReaderSettings();
            scrollReaderToPage(stream, current);
            updateReaderProgress(current, totalPages);
        });
    });

    document.querySelectorAll('[data-reader-theme]').forEach((button) => {
        button.addEventListener('click', () => {
            const settings = getReaderSettings();
            settings.theme = button.dataset.readerTheme;
            saveReaderSettings(settings);
            applyReaderSettings();
        });
    });

    document.querySelectorAll('[data-reader-fit]').forEach((button) => {
        button.addEventListener('click', () => {
            const settings = getReaderSettings();
            settings.fit = button.dataset.readerFit;
            saveReaderSettings(settings);
            applyReaderSettings();
        });
    });

    const flipTo = (direction) => {
        const current = currentReaderPage(stream) || 1;
        const items = [...stream.querySelectorAll('[data-page-number]')].map((el) => Number(el.dataset.pageNumber));
        const next = direction > 0 ? Math.min(...items.filter((n) => n > current), ...[Math.max(...items)]) : Math.max(...items.filter((n) => n < current), ...[Math.min(...items)]);
        scrollReaderToPage(stream, next, true);
        updateReaderProgress(next, totalPages);
    };
    document.querySelector('#flipPrev')?.addEventListener('click', () => flipTo(-1));
    document.querySelector('#flipNext')?.addEventListener('click', () => flipTo(1));
}

async function renderPdfPage(stream, pdf, pageNum, state) {
    if (state.rendered.has(pageNum) || state.rendering.has(pageNum)) {
        return;
    }
    const holder = stream.querySelector(`[data-pdf-page="${pageNum}"]`);
    if (!holder) {
        return;
    }
    state.rendering.add(pageNum);
    try {
        const page = await pdf.getPage(pageNum);
        const containerWidth = stream.clientWidth || holder.clientWidth || 800;
        const baseViewport = page.getViewport({ scale: 1 });
        const dpr = Math.min(window.devicePixelRatio || 1, 1.5);
        const scale = Math.max(1, containerWidth / baseViewport.width) * dpr;
        const viewport = page.getViewport({ scale });
        const canvas = document.createElement('canvas');
        canvas.width = Math.floor(viewport.width);
        canvas.height = Math.floor(viewport.height);
        holder.innerHTML = '';
        holder.appendChild(canvas);
        await page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise;
        state.rendered.add(pageNum);
    } catch (error) {
        holder.innerHTML = `<span class="pdf-page-loading">Failed to load page ${pageNum}.</span>`;
    }
    state.rendering.delete(pageNum);
}

function renderVisiblePdfPages(stream, pdf, state) {
    if (!pdf) {
        return;
    }
    const total = pdf.numPages;
    if (getReaderSettings().mode === 'flip') {
        const current = currentReaderPage(stream) || 1;
        [current - 1, current, current + 1].forEach((pageNum) => {
            if (pageNum >= 1 && pageNum <= total) {
                renderPdfPage(stream, pdf, pageNum, state);
            }
        });
        return;
    }
    const top = window.scrollY;
    const bottom = top + window.innerHeight;
    stream.querySelectorAll('[data-pdf-page]').forEach((holder) => {
        const rect = holder.getBoundingClientRect();
        const absTop = rect.top + window.scrollY;
        if (absTop < bottom + 1200 && absTop + rect.height > top - 1200) {
            renderPdfPage(stream, pdf, Number(holder.dataset.pdfPage), state);
        }
    });
}

function ensurePdfJs() {
    if (typeof pdfjsLib !== 'undefined') {
        return Promise.resolve();
    }
    if (window._pdfJsLoading) {
        return window._pdfJsLoading;
    }
    window._pdfJsLoading = new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = `${app.basePath}/assets/vendor/pdfjs/pdf.min.js`;
        script.onload = () => resolve();
        script.onerror = () => reject(new Error('PDF engine failed to load.'));
        document.head.appendChild(script);
    });
    return window._pdfJsLoading;
}

async function loadPdfViewer(stream, bookId) {
    const title = document.querySelector('#readerTitle');
    const back = document.querySelector('#bookBackLink');
    const controls = document.querySelector('#readerControls');
    stream.innerHTML = '<p class="muted">Loading PDF...</p>';
    try {
        try {
            await ensurePdfJs();
        } catch (engineError) {
            throw new Error('PDF engine failed to load. Refresh and try again.');
        }
        const data = await apiFetch(`book.php?id=${bookId}`);
        const book = data.book;
        if (!book || !book.pdf_file) {
            throw new Error('No PDF available for this book.');
        }
        document.title = `${book.title} - Bookshelf Reader`;
        title.textContent = book.title;
        back.href = `book.html?id=${Number(book.id)}`;
        back.textContent = `< ${book.title}`;
        if (app.currentUser) {
            apiFetch('user/history.php', {
                method: 'POST',
                body: JSON.stringify({ book_id: Number(book.id), csrf_token: app.csrfToken }),
            }).catch(() => {});
        }
        pdfjsLib.GlobalWorkerOptions.workerSrc = `${app.basePath}/assets/vendor/pdfjs/pdf.worker.min.js`;
        const pdf = await pdfjsLib.getDocument(app.asset(book.pdf_file)).promise;
        const total = pdf.numPages;
        const state = { rendered: new Set(), rendering: new Set() };

        stream.innerHTML = Array.from({ length: total }, (_, index) => `
            <figure class="reader-page pdf-page" data-page-number="${index + 1}" data-pdf-page="${index + 1}">
                <span class="pdf-page-loading">Loading page ${index + 1} of ${total}...</span>
            </figure>
        `).join('');

        controls.hidden = false;
        applyReaderSettings();
        initReaderControls({ bookId: Number(book.id) }, total);

        const renderVisible = () => renderVisiblePdfPages(stream, pdf, state);
        window.addEventListener('scroll', renderVisible, { passive: true });
        stream.addEventListener('scroll', renderVisible, { passive: true });
        renderVisible();

        const resume = Number(data.resume_page || 0);
        if (resume > 1 && resume <= total) {
            window.requestAnimationFrame(() => {
                scrollReaderToPage(stream, resume);
                updateReaderProgress(resume, total);
                window.setTimeout(renderVisible, 400);
            });
        } else {
            updateReaderProgress(1, total);
        }
    } catch (error) {
        stream.innerHTML = `<section class="empty-state"><h1>PDF not found</h1><p>${escapeHtml(error.message)}</p></section>`;
    }
}

async function loadReader() {
    const stream = document.querySelector('#readerStream');
    if (!stream) {
        return;
    }

    const params = queryParams();
    const chapterId = Number(params.get('chapter_id') || 0);
    const bookId = Number(params.get('book_id') || 0);
    const title = document.querySelector('#readerTitle');
    const back = document.querySelector('#bookBackLink');
    const topActions = document.querySelector('#topChapterActions');
    const bottomActions = document.querySelector('#bottomChapterActions');

    if (bookId > 0 && !chapterId) {
        await loadPdfViewer(stream, bookId);
        return;
    }

    stream.innerHTML = '<p class="muted">Loading chapter...</p>';
    try {
        const data = await apiFetch(`chapter.php?chapter_id=${chapterId}`);
        const chapter = data.chapter;
        document.title = `${chapter.book_title} - Chapter ${chapter.chapter_number}`;
        title.textContent = `Chapter ${chapter.chapter_number}: ${chapter.title}`;
        back.href = `book.html?id=${Number(chapter.book_id)}`;
        back.textContent = `< ${chapter.book_title}`;
        topActions.innerHTML = chapterButtons(data.previous_chapter_id, data.next_chapter_id, true);
        bottomActions.innerHTML = `${chapterButtons(data.previous_chapter_id, data.next_chapter_id)}<a class="button-link secondary" href="#" data-scroll-top>Back to top</a>`;

        if (!data.pages.length) {
            stream.innerHTML = '<div class="empty-state compact"><p>No pages have been uploaded for this chapter yet.</p></div>';
            return;
        }

        if (app.currentUser) {
            apiFetch('user/history.php', {
                method: 'POST',
                body: JSON.stringify({ chapter_id: chapterId, csrf_token: app.csrfToken }),
            }).catch(() => {});
        }

        stream.innerHTML = data.pages.map((page) => {
            if (page.content_type === 'image') {
                return `
                    <figure class="reader-page" data-page-number="${Number(page.page_number)}">
                        <img src="${escapeHtml(app.asset(page.content_path))}" alt="Page ${Number(page.page_number)}" loading="lazy">
                    </figure>
                `;
            }
            return `
                <section class="reader-text-page" data-page-number="${Number(page.page_number)}">
                    <span>Page ${Number(page.page_number)}</span>
                    <p>${nl2br(page.text_content || '')}</p>
                </section>
            `;
        }).join('');

        document.querySelector('#readerControls').hidden = false;
        applyReaderSettings();
        const total = data.pages.length;
        initReaderControls({ chapterId }, total);

        const resume = Number(data.resume_page || 0);
        if (resume > 0 && stream.querySelector(`[data-page-number="${resume}"]`)) {
            window.requestAnimationFrame(() => {
                scrollReaderToPage(stream, resume);
                updateReaderProgress(resume, total);
            });
        } else {
            updateReaderProgress(Number(stream.querySelector('[data-page-number]')?.dataset.pageNumber || 1), total);
        }
    } catch (error) {
        stream.innerHTML = `<section class="empty-state"><h1>Chapter not found</h1><p>${escapeHtml(error.message)}</p></section>`;
    }
}

async function initReaderLogin() {
    const loginForm = document.querySelector('#readerLoginForm');
    const registerForm = document.querySelector('#readerRegisterForm');
    const otpForm = document.querySelector('#readerOtpForm');
    if (!loginForm && !registerForm && !otpForm) {
        return;
    }

    if (app.currentUser) {
        window.location.href = 'profile.html';
        return;
    }

    const loginNotice = document.querySelector('#readerLoginNotice');
    const registerNotice = document.querySelector('#readerRegisterNotice');
    const otpMessage = document.querySelector('#readerOtpMessage');

    const showOtpForm = (email, message) => {
        if (!otpForm) {
            return;
        }
        otpForm.hidden = false;
        otpForm.querySelector('input[name="email"]').value = email;
        if (otpMessage) {
            otpMessage.textContent = message || 'Enter the OTP sent to your email.';
        }
        loginForm?.setAttribute('hidden', 'hidden');
        registerForm?.setAttribute('hidden', 'hidden');
    };

    if (loginForm) {
        loginForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            const formData = new FormData(loginForm);
            try {
                await apiFetch('user/session.php', {
                    method: 'POST',
                    body: JSON.stringify({
                        mode: 'login',
                        email: formData.get('email'),
                        password: formData.get('password'),
                        csrf_token: app.csrfToken,
                    }),
                });
                window.location.href = 'profile.html';
            } catch (error) {
                showNotice(loginNotice, error.message, 'error');
            }
        });
    }

    if (registerForm) {
        registerForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            const formData = new FormData(registerForm);
            try {
                const data = await apiFetch('user/session.php', {
                    method: 'POST',
                    body: JSON.stringify({
                        mode: 'register',
                        email: formData.get('email'),
                        password: formData.get('password'),
                        csrf_token: app.csrfToken,
                    }),
                });
                if (data.requires_verification) {
                    showOtpForm(data.email, data.message);
                    return;
                }
                window.location.href = 'profile.html';
            } catch (error) {
                showNotice(registerNotice, error.message, 'error');
            }
        });
    }

    if (otpForm) {
        otpForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            const formData = new FormData(otpForm);
            try {
                await apiFetch('user/session.php', {
                    method: 'POST',
                    body: JSON.stringify({
                        mode: 'verify',
                        email: formData.get('email'),
                        otp: formData.get('otp'),
                        csrf_token: app.csrfToken,
                    }),
                });
                window.location.href = 'profile.html';
            } catch (error) {
                showNotice(loginNotice || registerNotice, error.message, 'error');
            }
        });
    }
}

async function loadProfile() {
    const history = document.querySelector('#profileHistory');
    if (!history) {
        return;
    }

    try {
        const data = await apiFetch('user/profile.php');
        const latestCover = data.history.find((item) => item.cover_image)?.cover_image;
        const cover = document.querySelector('#profileCover');
        if (latestCover && cover) {
            cover.style.backgroundImage = `url("${app.asset(latestCover)}")`;
        }

        const username = usernameFromEmail(data.user.email);
        document.querySelector('#profileName').textContent = username;
        document.querySelector('#profileEmail').textContent = `@${username}`;

        const avatar = document.querySelector('#profileAvatar');
        const fallback = document.querySelector('#profileAvatarFallback');
        if (data.user.profile_image && avatar) {
            avatar.src = app.asset(data.user.profile_image);
            avatar.hidden = false;
            fallback.hidden = true;
        } else {
            fallback.textContent = username.slice(0, 1).toUpperCase();
        }

        const statReading = document.querySelector('#statReading');
        const statWishlist = document.querySelector('#statWishlist');
        if (statReading) {
            statReading.textContent = data.stats?.reading_books ?? data.history.length;
        }
        if (statWishlist) {
            statWishlist.textContent = data.stats?.wishlist_books ?? data.wishlist.length;
        }

        history.innerHTML = data.history.length
            ? data.history.map((item) => bookCardHtml({
                id: item.id,
                title: item.title,
                author: item.author,
                cover_image: item.cover_image,
                pdf_file: item.pdf_file,
            }, item.chapter_id
                ? `<p class="card-progress">Continue Ch. ${escapeHtml(item.chapter_number)}</p>`
                : '<p class="card-progress">PDF book</p>')).join('')
            : '<section class="empty-state compact"><p>No reading history yet.</p><a class="button-link" href="index.html">Discover books</a></section>';
        document.querySelector('#profileWishlist').innerHTML = data.wishlist.length
            ? data.wishlist.map((item) => bookCardHtml({
                id: item.id,
                title: item.title,
                author: item.author,
                cover_image: item.cover_image,
            })).join('')
            : '<section class="empty-state compact"><p>No wishlist books yet.</p><a class="button-link" href="index.html">Discover books</a></section>';
    } catch (error) {
        window.location.href = 'login.html';
    }
}

function initProfileInteractions() {
    const imageForm = document.querySelector('#profileImageForm');
    if (!imageForm) {
        return;
    }

    document.querySelector('#profileImageButton')?.addEventListener('click', () => {
        imageForm.querySelector('input[type="file"]').click();
    });

    document.querySelector('[data-reader-logout]')?.addEventListener('click', (event) => {
        event.preventDefault();
        handleSignOut();
    });

    const profileImageInput = imageForm.querySelector('input[name="profile_image"]');
    profileImageInput.addEventListener('change', async () => {
        if (!profileImageInput.files.length) {
            return;
        }

        const file = profileImageInput.files[0];
        if (file.size > 10 * 1024 * 1024) {
            window.alert('Image is too large. Maximum size is 10 MB.');
            profileImageInput.value = '';
            return;
        }

        const formData = new FormData(imageForm);
        formData.append('csrf_token', app.csrfToken);
        try {
            const data = await apiFetch('user/profile.php', { method: 'POST', body: formData });
            const avatar = document.querySelector('#profileAvatar');
            const fallback = document.querySelector('#profileAvatarFallback');
            avatar.src = app.asset(data.profile_image);
            avatar.hidden = false;
            fallback.hidden = true;
            if (app.currentUser) {
                app.currentUser.profile_image = data.profile_image;
            }
            updateHeaderProfile();
        } catch (error) {
            window.alert(error.message);
        }
    });

    document.querySelectorAll('[data-profile-tab]').forEach((tab) => {
        tab.addEventListener('click', () => {
            const target = tab.dataset.profileTab;
            document.querySelectorAll('[data-profile-tab]').forEach((item) => item.classList.toggle('active', item === tab));
            document.querySelectorAll('[data-profile-panel]').forEach((panel) => {
                panel.classList.toggle('active', panel.dataset.profilePanel === target);
            });
        });
    });
}

async function loadLibrary() {
    const grid = document.querySelector('#libraryGrid');
    if (!grid) {
        return;
    }

    const empty = document.querySelector('#libraryEmpty');
    try {
        const data = await apiFetch('user/profile.php');
        grid.innerHTML = '';
        empty.hidden = data.history.length > 0;
        data.history.forEach((book) => {
            grid.insertAdjacentHTML(
                'beforeend',
                bookCardHtml(book, book.chapter_id ? `<p>Continue Ch. ${escapeHtml(book.chapter_number)}</p>` : '<p>PDF book</p>')
            );
        });
    } catch (error) {
        grid.innerHTML = '<section class="empty-state compact"><p>Please log in to view your library.</p><a class="button-link" href="login.html">Login</a></section>';
        empty.hidden = true;
    }
}

async function loadSetup() {
    const form = document.querySelector('#setupForm');
    if (!form) {
        return;
    }

    const notice = document.querySelector('#setupNotice');
    const message = document.querySelector('#setupMessage');

    try {
        const data = await apiFetch('setup_admin.php');
        app.csrfToken = data.csrf_token;
        if (data.locked) {
            form.hidden = true;
            message.textContent = 'An admin account already exists. This setup page is locked.';
        }
    } catch (error) {
        showNotice(notice, error.message, 'error');
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const formData = new FormData(form);
        try {
            const data = await apiFetch('setup_admin.php', {
                method: 'POST',
                body: JSON.stringify({
                    email: formData.get('email'),
                    password: formData.get('password'),
                    confirm_password: formData.get('confirm_password'),
                    csrf_token: app.csrfToken,
                }),
            });
            form.hidden = true;
            showNotice(notice, `${data.message} You can now log in.`, 'success');
        } catch (error) {
            showNotice(notice, error.message, 'error');
        }
    });
}

async function initLogin() {
    const form = document.querySelector('#loginForm');
    if (!form) {
        return;
    }

    const notice = document.querySelector('#loginNotice');
    try {
        const session = await apiFetch('admin/session.php');
        app.csrfToken = session.csrf_token;
        if (session.user && session.user.role === 'admin') {
            window.location.href = 'index.html';
        }
    } catch (error) {
        showNotice(notice, error.message, 'error');
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const formData = new FormData(form);
        try {
            await apiFetch('admin/session.php', {
                method: 'POST',
                body: JSON.stringify({
                    email: formData.get('email'),
                    password: formData.get('password'),
                    csrf_token: app.csrfToken,
                }),
            });
            window.location.href = 'index.html';
        } catch (error) {
            showNotice(notice, error.message, 'error');
        }
    });
}

function fillSelect(select, options, placeholder, formatter) {
    select.innerHTML = `<option value="">${escapeHtml(placeholder)}</option>`;
    options.forEach((option) => {
        const item = document.createElement('option');
        item.value = option.id;
        item.textContent = formatter(option);
        select.appendChild(item);
    });
}

async function loadAdminDashboard() {
    const notice = document.querySelector('#adminNotice');
    if (!notice) {
        return;
    }

    try {
        const data = await apiFetch('admin/dashboard.php');
        app.csrfToken = data.csrf_token;
        app.adminBooks = data.books;

        document.querySelectorAll('[data-book-select]').forEach((select) => {
            fillSelect(select, data.books, 'Choose a book', (book) => book.title);
        });
        document.querySelectorAll('[data-chapter-select]').forEach((select) => {
            fillSelect(select, data.chapters, 'Choose a chapter', (chapter) => `${chapter.book_title} - Ch. ${chapter.chapter_number}: ${chapter.title}`);
        });
        const adminBookCount = document.querySelector('#adminBookCount');
        const adminChapterCount = document.querySelector('#adminChapterCount');
        const adminGenreCount = document.querySelector('#adminGenreCount');
        if (adminBookCount) {
            adminBookCount.textContent = data.books.length;
        }
        if (adminChapterCount) {
            adminChapterCount.textContent = data.chapters.length;
        }
        if (adminGenreCount) {
            adminGenreCount.textContent = data.genres.length;
        }

        const genreOptions = document.querySelector('#genreOptions');
        if (genreOptions) {
            genreOptions.innerHTML = data.genres.length
                ? data.genres.map((genre) => `
                    <label>
                        <input type="checkbox" name="genre_ids[]" value="${Number(genre.id)}">
                        ${escapeHtml(genre.name)}
                    </label>
                `).join('')
                : '<p class="muted">Run the genre migration to add genre options.</p>';
        }

        const bookManager = document.querySelector('#bookManager');
        bookManager.innerHTML = data.books.length
            ? data.books.map((book) => `
                <div class="mini-row manager-row" data-book-title-search="${escapeHtml((book.title || '').toLowerCase())}">
                    <span class="manager-cover">${coverMarkup(book)}</span>
                    <span class="manager-meta">
                        <strong>${escapeHtml(book.title)}</strong>
                        <small class="manager-genres">${escapeHtml((book.genres || []).map((genre) => genre.name).join(', ') || 'No genre')}</small>
                    </span>
                    <label class="popular-toggle">
                        <input type="checkbox" data-toggle-popular="${Number(book.id)}" ${Number(book.is_popular) ? 'checked' : ''}>
                        Popular
                    </label>
                    <span class="manager-actions">
                        <a class="muted-link" href="../book.html?id=${Number(book.id)}">View</a>
                        <button class="secondary-button" type="button" data-edit-book="${Number(book.id)}">Edit</button>
                        <button class="danger-button" type="button" data-delete-book="${Number(book.id)}" data-book-title="${escapeHtml(book.title)}">Delete</button>
                    </span>
                </div>
            `).join('')
            : '<p class="muted">No books yet.</p>';
        applyBookFilter();
        await loadAdminSections();
        await loadAdminGenres();

        const recentBooks = document.querySelector('#recentBooks');
        recentBooks.innerHTML = data.recent_books.length
            ? data.recent_books.map((book) => `<a class="mini-row" href="../book.html?id=${Number(book.id)}"><span>${escapeHtml(book.title)}</span><small>${escapeHtml(new Date(book.created_at).toLocaleDateString())}</small></a>`).join('')
            : '<p class="muted">No books yet.</p>';

        const recentChapters = document.querySelector('#recentChapters');
        recentChapters.innerHTML = data.recent_chapters.length
            ? data.recent_chapters.map((chapter) => `<a class="mini-row" href="../reader.html?chapter_id=${Number(chapter.id)}"><span>${escapeHtml(chapter.book_title)} - Ch. ${escapeHtml(chapter.chapter_number)}</span><small>${escapeHtml(chapter.title)}</small></a>`).join('')
            : '<p class="muted">No chapters yet.</p>';

        await loadAdminRequests();
        await loadAdminBell();
    } catch (error) {
        window.location.href = 'login.html';
    }
}

async function loadAdminSections() {
    const manager = document.querySelector('#sectionManager');
    if (!manager) {
        return;
    }
    try {
        const data = await apiFetch('admin/sections.php');
        app.csrfToken = data.csrf_token || app.csrfToken;
        const sections = data.sections || [];
        const books = app.adminBooks || [];
        manager.innerHTML = sections.length
            ? sections.map((section) => {
                const selectedIds = (section.books || []).map((book) => Number(book.id));
                return `
                <article class="section-editor" data-section-id="${Number(section.id)}">
                    <div class="admin-panel-head">
                        <input type="text" value="${escapeHtml(section.title)}" data-section-title maxlength="255" aria-label="Section title">
                        <span class="muted">${selectedIds.length} book${selectedIds.length === 1 ? '' : 's'}</span>
                    </div>
                    <input type="text" class="section-subtitle-input" value="${escapeHtml(section.subtitle || '')}" data-section-subtitle maxlength="255" placeholder="Subtitle (optional)" aria-label="Section subtitle">
                    <div class="section-book-grid">
                        ${books.length
                            ? books.map((book) => `
                                <label>
                                    <input type="checkbox" data-section-book value="${Number(book.id)}" ${selectedIds.includes(Number(book.id)) ? 'checked' : ''}>
                                    ${escapeHtml(book.title)}
                                </label>`).join('')
                            : '<p class="muted">Add books first.</p>'}
                    </div>
                    <div class="form-actions">
                        <button type="button" class="button-link" data-save-section="${Number(section.id)}">Save section</button>
                        <button type="button" class="danger-button" data-delete-section="${Number(section.id)}" data-section-title="${escapeHtml(section.title)}">Delete</button>
                    </div>
                </article>`;
            }).join('')
            : '<p class="muted">No sections yet. Create one above.</p>';
    } catch (error) {
        manager.innerHTML = `<p class="muted">${escapeHtml(error.message)}</p>`;
    }
}

async function loadAdminGenres() {
    const manager = document.querySelector('#genreManager');
    if (!manager) {
        return;
    }
    try {
        const data = await apiFetch('admin/genres.php');
        app.csrfToken = data.csrf_token || app.csrfToken;
        const genres = data.genres || [];
        manager.innerHTML = genres.length
            ? genres.map((genre) => {
                const count = Number(genre.book_count || 0);
                return `
                <div class="genre-row" data-genre-id="${Number(genre.id)}">
                    <input type="text" value="${escapeHtml(genre.name)}" data-genre-name maxlength="80" aria-label="Genre name">
                    <span class="muted">${count} book${count === 1 ? '' : 's'}</span>
                    <span class="manager-actions">
                        <button class="secondary-button" type="button" data-save-genre="${Number(genre.id)}">Save</button>
                        <button class="danger-button" type="button" data-delete-genre="${Number(genre.id)}" data-genre-name="${escapeHtml(genre.name)}" data-genre-count="${count}">Delete</button>
                    </span>
                </div>`;
            }).join('')
            : '<p class="muted">No genres yet. Add one above.</p>';
    } catch (error) {
        manager.innerHTML = `<p class="muted">${escapeHtml(error.message)}</p>`;
    }
}

function initGenreForms() {
    const notice = document.querySelector('#adminNotice');
    const manager = document.querySelector('#genreManager');
    if (!manager) {
        return;
    }

    document.querySelector('#genreForm')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        const name = String(new FormData(form).get('name') || '').trim();
        try {
            const data = await apiFetch('admin/genres.php', {
                method: 'POST',
                body: JSON.stringify({ action: 'create', name, csrf_token: app.csrfToken }),
            });
            showNotice(notice, data.message);
            form.reset();
            await loadAdminDashboard();
        } catch (error) {
            showNotice(notice, error.message, 'error');
        }
    });

    manager.addEventListener('click', async (event) => {
        const saveButton = event.target.closest('[data-save-genre]');
        const deleteButton = event.target.closest('[data-delete-genre]');

        if (saveButton) {
            const row = saveButton.closest('.genre-row');
            const genreId = Number(saveButton.dataset.saveGenre);
            const name = row?.querySelector('[data-genre-name]')?.value || '';
            try {
                const data = await apiFetch('admin/genres.php', {
                    method: 'POST',
                    body: JSON.stringify({ action: 'update', id: genreId, name, csrf_token: app.csrfToken }),
                });
                showNotice(notice, data.message);
                await loadAdminDashboard();
            } catch (error) {
                showNotice(notice, error.message, 'error');
            }
            return;
        }

        if (deleteButton) {
            const genreId = Number(deleteButton.dataset.deleteGenre);
            const name = deleteButton.dataset.genreName || 'this genre';
            const count = Number(deleteButton.dataset.genreCount || 0);
            const warning = count > 0
                ? `Delete the "${name}" genre? It will be removed from ${count} book${count === 1 ? '' : 's'}.`
                : `Delete the "${name}" genre?`;
            if (!window.confirm(warning)) {
                return;
            }
            try {
                const data = await apiFetch('admin/genres.php', {
                    method: 'DELETE',
                    body: JSON.stringify({ id: genreId, csrf_token: app.csrfToken }),
                });
                showNotice(notice, data.message);
                await loadAdminDashboard();
            } catch (error) {
                showNotice(notice, error.message, 'error');
            }
        }
    });
}

function initSectionForms() {
    const notice = document.querySelector('#adminNotice');
    const manager = document.querySelector('#sectionManager');
    if (!manager) {
        return;
    }

    document.querySelector('#sectionForm')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        const formData = new FormData(form);
        const title = String(formData.get('title') || '').trim();
        const subtitle = String(formData.get('subtitle') || '').trim();
        try {
            const data = await apiFetch('admin/sections.php', {
                method: 'POST',
                body: JSON.stringify({ action: 'create', title, subtitle, csrf_token: app.csrfToken }),
            });
            showNotice(notice, data.message);
            form.reset();
            await loadAdminSections();
        } catch (error) {
            showNotice(notice, error.message, 'error');
        }
    });

    manager.addEventListener('click', async (event) => {
        const saveButton = event.target.closest('[data-save-section]');
        const deleteButton = event.target.closest('[data-delete-section]');

        if (saveButton) {
            const editor = saveButton.closest('.section-editor');
            const sectionId = Number(saveButton.dataset.saveSection);
            const title = editor?.querySelector('[data-section-title]')?.value || '';
            const subtitle = editor?.querySelector('[data-section-subtitle]')?.value || '';
            const bookIds = [...(editor?.querySelectorAll('[data-section-book]:checked') || [])].map((box) => Number(box.value));
            try {
                const data = await apiFetch('admin/sections.php', {
                    method: 'POST',
                    body: JSON.stringify({ action: 'update', id: sectionId, title, subtitle, book_ids: bookIds, csrf_token: app.csrfToken }),
                });
                showNotice(notice, data.message);
                await loadAdminSections();
            } catch (error) {
                showNotice(notice, error.message, 'error');
            }
            return;
        }

        if (deleteButton) {
            const sectionId = Number(deleteButton.dataset.deleteSection);
            const title = deleteButton.dataset.sectionTitle || 'this section';
            if (!window.confirm(`Delete the "${title}" section? Books stay in the library.`)) {
                return;
            }
            try {
                const data = await apiFetch('admin/sections.php', {
                    method: 'DELETE',
                    body: JSON.stringify({ id: sectionId, csrf_token: app.csrfToken }),
                });
                showNotice(notice, data.message);
                await loadAdminSections();
            } catch (error) {
                showNotice(notice, error.message, 'error');
            }
        }
    });
}

function applyBookFilter() {
    const filter = document.querySelector('#bookFilter');
    const query = (filter?.value || '').trim().toLowerCase();
    document.querySelectorAll('#bookManager .manager-row').forEach((row) => {
        row.hidden = query !== '' && !(row.dataset.bookTitleSearch || '').includes(query);
    });
}

function initAdminTabs() {
    const tabs = document.querySelectorAll('[data-admin-tab]');
    if (!tabs.length) {
        return;
    }
    tabs.forEach((tab) => {
        tab.addEventListener('click', () => {
            const target = tab.dataset.adminTab;
            tabs.forEach((item) => item.classList.toggle('active', item === tab));
            document.querySelectorAll('[data-admin-panel]').forEach((panel) => {
                panel.classList.toggle('active', panel.dataset.adminPanel === target);
            });
        });
    });
    // Deep-link from the side panel "New Request" row: admin/index.html#requests.
    if (window.location.hash === '#requests') {
        document.querySelector('[data-admin-tab="requests"]')?.click();
    }
    document.querySelector('#bookFilter')?.addEventListener('input', applyBookFilter);
}

function appendCsrf(formData) {
    formData.append('csrf_token', app.csrfToken);
    return formData;
}

function initAdminForms() {
    const notice = document.querySelector('#adminNotice');
    const bookForm = document.querySelector('#bookForm');
    if (!bookForm) {
        return;
    }

    bookForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        try {
            const data = await apiFetch('admin/books.php', { method: 'POST', body: appendCsrf(new FormData(bookForm)) });
            showNotice(notice, data.message);
            bookForm.reset();
            await loadAdminDashboard();
        } catch (error) {
            showNotice(notice, error.message, 'error');
        }
    });

    const chapterForm = document.querySelector('#chapterForm');

if (chapterForm) {
    chapterForm.addEventListener('submit', async (event) => {
        event.preventDefault();

        const form = event.currentTarget;
        const formData = new FormData(form);

        try {
            const data = await apiFetch('admin/chapters.php', {
                method: 'POST',
                body: JSON.stringify({
                    book_id: formData.get('book_id'),
                    title: formData.get('title'),
                    chapter_number: formData.get('chapter_number'),
                    csrf_token: app.csrfToken,
                }),
            });

            showNotice(notice, data.message);

            if (form) {
                form.reset();
            }

            await loadAdminDashboard();

        } catch (error) {
            showNotice(notice, error.message, 'error');
        }
    });
}
    
    document.querySelector('#imagePagesForm')?.addEventListener('submit', async (event) => {
    event.preventDefault();

    const form = event.currentTarget;
    const formData = appendCsrf(new FormData(form));
    formData.append('mode', 'images');

    try {
        const data = await apiFetch('admin/pages.php', {
            method: 'POST',
            body: formData
        });

        showNotice(notice, data.message);

        if (form) {
            form.reset();
        }
    } catch (error) {
        showNotice(notice, error.message, 'error');
    }
});

    document.querySelector('#textPageForm')?.addEventListener('submit', async (event) => {
    event.preventDefault();

    const form = event.currentTarget;
    const formData = appendCsrf(new FormData(form));
    formData.append('mode', 'text');

    try {
        const data = await apiFetch('admin/pages.php', {
            method: 'POST',
            body: formData
        });

        showNotice(notice, data.message);

        if (form) {
            form.reset();
        }
    } catch (error) {
        showNotice(notice, error.message, 'error');
    }
});

    document.querySelector('#logoutLink')?.addEventListener('click', async (event) => {
        event.preventDefault();
        await apiFetch('admin/session.php', { method: 'DELETE' });
        window.location.href = 'login.html';
    });

    document.querySelector('#bookManager')?.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-delete-book]');
        if (!button) {
            return;
        }

        const bookId = Number(button.dataset.deleteBook);
        const title = button.dataset.bookTitle || 'this book';
        if (!window.confirm(`Delete "${title}" and all of its chapters/pages?`)) {
            return;
        }

        try {
            const data = await apiFetch('admin/books.php', {
                method: 'DELETE',
                body: JSON.stringify({ id: bookId, csrf_token: app.csrfToken }),
            });
            showNotice(notice, data.message);
            await loadAdminDashboard();
        } catch (error) {
            showNotice(notice, error.message, 'error');
        }
    });

    document.querySelector('#bookManager')?.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-edit-book]');
        if (!button) {
            return;
        }

        const bookId = Number(button.dataset.editBook);
        const book = (app.adminBooks || []).find((b) => Number(b.id) === bookId);
        if (!book) {
            return;
        }

        document.querySelector('[data-admin-tab="books"]')?.click();

        const form = document.querySelector('#bookForm');
        const title = document.querySelector('#bookFormTitle');
        const cancelBtn = document.querySelector('#cancelBookEdit');

        form.querySelector('[name="id"]').value = book.id;
        form.querySelector('[name="title"]').value = book.title || '';
        form.querySelector('[name="author"]').value = book.author || '';
        form.querySelector('[name="description"]').value = book.description || '';

        const genreCheckboxes = form.querySelectorAll('[name="genre_ids[]"]');
        const bookGenreIds = (book.genres || []).map((g) => Number(g.id));
        genreCheckboxes.forEach((cb) => {
            cb.checked = bookGenreIds.includes(Number(cb.value));
        });

        const popularCb = form.querySelector('[name="is_popular"]');
        if (popularCb) {
            popularCb.checked = !!Number(book.is_popular);
        }

        if (title) {
            title.textContent = 'Edit book';
        }
        if (cancelBtn) {
            cancelBtn.hidden = false;
        }
        form.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });

    document.querySelector('#cancelBookEdit')?.addEventListener('click', () => {
        const form = document.querySelector('#bookForm');
        const title = document.querySelector('#bookFormTitle');
        const cancelBtn = document.querySelector('#cancelBookEdit');

        form.reset();
        form.querySelector('[name="id"]').value = '';
        if (title) {
            title.textContent = 'Add book';
        }
        if (cancelBtn) {
            cancelBtn.hidden = true;
        }
    });

    document.querySelector('#bookManager')?.addEventListener('change', async (event) => {
        const toggle = event.target.closest('[data-toggle-popular]');
        if (!toggle) {
            return;
        }

        try {
            const data = await apiFetch('admin/books.php', {
                method: 'PATCH',
                body: JSON.stringify({
                    id: Number(toggle.dataset.togglePopular),
                    is_popular: toggle.checked,
                    csrf_token: app.csrfToken,
                }),
            });
            showNotice(notice, data.message);
        } catch (error) {
            toggle.checked = !toggle.checked;
            showNotice(notice, error.message, 'error');
        }
    });
}

function initCoverPreview() {
    const coverInput = document.querySelector('[data-cover-input]');
    const coverPreview = document.querySelector('[data-cover-preview]');
    if (!coverInput || !coverPreview) {
        return;
    }

    coverInput.addEventListener('change', () => {
        const file = coverInput.files && coverInput.files[0];
        if (!file) {
            coverPreview.hidden = true;
            coverPreview.removeAttribute('src');
            return;
        }
        coverPreview.src = URL.createObjectURL(file);
        coverPreview.hidden = false;
    });
}

function initScrollTop() {
    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-scroll-top]');
        if (!button) {
            return;
        }
        event.preventDefault();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });
}

function initReaderActions() {
    document.addEventListener('click', async (event) => {
        const wishlistButton = event.target.closest('[data-wishlist-toggle]');
        if (wishlistButton) {
            if (wishlistButton.dataset.requiresLogin === 'true' || !app.currentUser) {
                window.location.href = 'login.html';
                return;
            }

            const bookId = Number(wishlistButton.dataset.bookId);
            const action = wishlistButton.dataset.inWishlist === 'true' ? 'remove' : 'add';
            const originalLabel = wishlistButton.textContent;
            wishlistButton.disabled = true;
            try {
                const data = await apiFetch('user/wishlist.php', {
                    method: 'POST',
                    body: JSON.stringify({ book_id: bookId, action, csrf_token: app.csrfToken }),
                });
                wishlistButton.textContent = data.in_wishlist ? 'Remove from wishlist' : 'Add to wishlist';
                wishlistButton.dataset.inWishlist = data.in_wishlist ? 'true' : 'false';
            } catch (error) {
                wishlistButton.textContent = originalLabel || (action === 'remove' ? 'Remove from wishlist' : 'Add to wishlist');
            } finally {
                wishlistButton.disabled = false;
            }
        }

    });
}

function initSearchToggle() {
    const toggle = document.querySelector('.search-toggle');
    const overlay = document.querySelector('#searchOverlay');
    const closeBtn = document.querySelector('#searchClose');
    const searchInput = overlay?.querySelector('input[name="q"]');

    if (!toggle || !overlay) {
        return;
    }

    function isMobile() {
        return window.innerWidth <= 980;
    }

    toggle.addEventListener('click', () => {
        if (isMobile()) {
            overlay.classList.add('open');
            searchInput?.focus();
        }
    });

    closeBtn?.addEventListener('click', () => {
        overlay.classList.remove('open');
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && overlay.classList.contains('open')) {
            overlay.classList.remove('open');
        }
    });
}

function initUserPanel() {
    const toggle = document.querySelector('#userPanelToggle');
    const panel = document.querySelector('#userPanel');
    const backdrop = document.querySelector('#userPanelBackdrop');
    const closeBtn = document.querySelector('#userPanelClose');
    const body = document.querySelector('#userPanelBody');

    if (!toggle || !panel || !body) {
        return;
    }

    function openPanel() {
        populateUserPanel(body);
        panel.classList.add('open');
        if (app.currentUser) {
            loadMyRequests();
            loadAdminPendingBadge();
        }
        if (backdrop) {
            backdrop.hidden = false;
            requestAnimationFrame(() => backdrop.classList.add('open'));
        }
    }

    function closePanel() {
        panel.classList.remove('open');
        if (backdrop) {
            backdrop.classList.remove('open');
            setTimeout(() => { backdrop.hidden = true; }, 250);
        }
    }

    toggle.addEventListener('click', openPanel);
    closeBtn?.addEventListener('click', closePanel);
    backdrop?.addEventListener('click', closePanel);

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && panel.classList.contains('open')) {
            closePanel();
        }
    });
}

function populateUserPanel(container) {
    const user = app.currentUser;
    const arrowSvg = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>';

    if (!user) {
        container.innerHTML = `
            <div class="panel-guest-avatar">
                <div class="panel-guest-icon">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                </div>
                <div class="panel-guest-info">
                    <h3>Guest</h3>
                    <p>Explore books, read more 📖</p>
                </div>
            </div>
            <div class="panel-divider"></div>
            <button type="button" class="panel-row" onclick="toggleTheme()">
                <span class="panel-row-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path></svg>
                </span>
                <span class="panel-row-label">Theme</span>
                <span class="panel-row-value">${document.documentElement.dataset.theme === 'dark' ? 'Dark' : 'Light'}</span>
            </button>
            <div class="panel-divider"></div>
            <a href="login.html" class="panel-btn-primary">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                Sign In
                <span style="margin-left:auto">${arrowSvg}</span>
            </a>
            <a href="signup.html" class="panel-link-secondary">Register</a>
        `;
        return;
    }

    const initial = usernameFromEmail(user.email).slice(0, 1).toUpperCase();
    const avatarHtml = user.profile_image
        ? `<img src="${escapeHtml(app.asset(user.profile_image))}" alt="Profile">`
        : initial;
    const isAdminUser = user.role === 'admin';
    const adminRequestsUrl = `${app.basePath}/admin/index.html#requests`;
    const requestSection = isAdminUser
        ? `
        <a href="${escapeHtml(adminRequestsUrl)}" class="panel-row" data-admin-requests-link>
            <span class="panel-row-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
            </span>
            <span class="panel-row-label">New Request <span class="status-pill pending" data-admin-pending-count hidden></span></span>
            <span class="panel-row-arrow">${arrowSvg}</span>
        </a>`
        : `
        <button type="button" class="panel-row" data-request-book-toggle>
            <span class="panel-row-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            </span>
            <span class="panel-row-label">Request a Book</span>
            <span class="panel-row-arrow">${arrowSvg}</span>
        </button>
        <div data-request-book-wrap hidden>
            <form class="request-form" id="requestBookForm">
                <label>
                    Book title
                    <input type="text" name="title" required maxlength="255" placeholder="Book title" autocomplete="off">
                </label>
                <label>
                    Author
                    <input type="text" name="author" required maxlength="255" placeholder="Author name" autocomplete="off">
                </label>
                <button type="submit" class="panel-btn-primary">Send request</button>
                <p class="request-status" data-request-status role="status"></p>
            </form>
            <div class="request-list" data-my-requests><p class="muted">Loading your requests...</p></div>
        </div>`;

    container.innerHTML = `
        <div class="panel-user-avatar">
            <div class="panel-user-icon">${avatarHtml}</div>
            <div class="panel-user-info">
                <h3>${escapeHtml(usernameFromEmail(user.email))}</h3>
                <p>${escapeHtml(user.email)}</p>
            </div>
        </div>
        <div class="panel-divider"></div>
        <p class="panel-section-title">Navigation</p>
        <a href="profile.html" class="panel-row">
            <span class="panel-row-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
            </span>
            <span class="panel-row-label">Profile</span>
            <span class="panel-row-arrow">${arrowSvg}</span>
        </a>
        <a href="library.html" class="panel-row">
            <span class="panel-row-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg>
            </span>
            <span class="panel-row-label">Library</span>
            <span class="panel-row-arrow">${arrowSvg}</span>
        </a>
        ${requestSection}
        <div class="panel-divider"></div>
        <p class="panel-section-title">Settings</p>
        <button type="button" class="panel-row" onclick="toggleTheme()">
            <span class="panel-row-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path></svg>
            </span>
            <span class="panel-row-label">Theme</span>
            <span class="panel-row-value">${document.documentElement.dataset.theme === 'dark' ? 'Dark' : 'Light'}</span>
        </button>
        <div class="panel-divider"></div>
        <button type="button" class="panel-row" onclick="handleSignOut()" style="color: var(--orange);">
            <span class="panel-row-icon" style="background: rgba(255,107,0,0.1);">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
            </span>
            <span class="panel-row-label">Sign Out</span>
        </button>
    `;
}

async function handleSignOut() {
    try {
        await apiFetch('user/session.php', { method: 'DELETE' });
    } catch (e) {
        // ignore
    }
    window.location.href = 'index.html';
}

const BELL_SVG = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>';

function removeBell() {
    document.querySelectorAll('[data-notification-bell]').forEach((el) => el.remove());
    document.querySelectorAll('[data-notification-dropdown]').forEach((el) => el.remove());
}

function mountBell(count) {
    removeBell();
    const navActions = document.querySelector('.nav-actions');
    if (!navActions) {
        return null;
    }
    const wrap = document.createElement('div');
    wrap.className = 'nav-bell-wrap';
    wrap.setAttribute('data-notification-bell', 'true');
    wrap.innerHTML = `
        <button type="button" class="notification-bell" data-bell-toggle aria-label="Notifications">
            ${BELL_SVG}
            ${count > 0 ? `<span class="bell-count">${count > 9 ? '9+' : count}</span>` : '<span class="bell-dot" hidden></span>'}
        </button>
        <div class="notification-dropdown" data-notification-dropdown hidden></div>
    `;
    // Bell sits to the LEFT of the search bar toggle, per spec.
    const searchToggle = navActions.querySelector('.search-toggle');
    if (searchToggle) {
        navActions.insertBefore(wrap, searchToggle);
    } else {
        navActions.insertBefore(wrap, navActions.firstChild);
    }
    return wrap;
}

function closeBellDropdown() {
    document.querySelectorAll('[data-notification-dropdown]').forEach((el) => {
        el.hidden = true;
    });
}

async function initNotifications() {
    const user = app.currentUser;
    if (!user) {
        removeBell();
        return;
    }
    const isAdminPage = !!document.querySelector('#adminNotice');
    const isAdmin = user.role === 'admin';

    if (isAdminPage && isAdmin) {
        await loadAdminBell();
        await loadAdminRequests();
        return;
    }

    // Regular reader bell: only visible when a requested book was uploaded.
    try {
        const data = await apiFetch('user/book_requests.php');
        const notifications = data.notifications || [];
        if (!notifications.length) {
            removeBell();
            return;
        }
        const wrap = mountBell(notifications.length);
        if (!wrap) {
            return;
        }
        const dropdown = wrap.querySelector('[data-notification-dropdown]');
        const toggle = wrap.querySelector('[data-bell-toggle]');
        toggle.addEventListener('click', async (event) => {
            event.stopPropagation();
            const willOpen = dropdown.hidden;
            closeBellDropdown();
            if (willOpen) {
                dropdown.innerHTML = notifications.map((item) => {
                    const bookId = Number(item.fulfilled_book_id || 0);
                    const link = bookId ? `book.html?id=${bookId}` : 'index.html';
                    return `<a class="notification-item" href="${link}"><strong>Your requested book is now on the site</strong><span>${escapeHtml(item.title)} &middot; ${escapeHtml(item.author)}</span><br><small>Tap to open</small></a>`;
                }).join('');
                dropdown.hidden = false;
                // Start the 24h expiry window on first open.
                try {
                    await apiFetch('user/book_requests.php', {
                        method: 'POST',
                        body: JSON.stringify({ action: 'seen', csrf_token: app.csrfToken }),
                    });
                } catch (error) {
                    // Bell still works even if the seen-marker fails.
                }
            } else {
                dropdown.hidden = true;
            }
        });
    } catch (error) {
        removeBell();
    }
}

async function loadAdminBell() {
    try {
        const data = await apiFetch('admin/book_requests.php');
        const pending = Number(data.pending_count || 0);
        const requests = data.requests || [];
        if (pending <= 0) {
            removeBell();
            return;
        }
        const wrap = mountBell(pending);
        if (!wrap) {
            return;
        }
        const dropdown = wrap.querySelector('[data-notification-dropdown]');
        const toggle = wrap.querySelector('[data-bell-toggle]');
        toggle.addEventListener('click', (event) => {
            event.stopPropagation();
            const willOpen = dropdown.hidden;
            closeBellDropdown();
            if (willOpen) {
                const pendingItems = requests.filter((item) => item.status === 'pending').slice(0, 8);
                dropdown.innerHTML = `<h3>Book requests</h3>` + (pendingItems.length
                    ? pendingItems.map((item) => `<a class="notification-item" href="#" data-goto-requests><strong>A user requested a book</strong><span>${escapeHtml(item.title)} &middot; ${escapeHtml(item.author)}</span><br><small>${escapeHtml(item.user_email || 'reader')}</small></a>`).join('')
                    : '<p class="notification-empty">No pending requests.</p>');
                dropdown.hidden = false;
            } else {
                dropdown.hidden = true;
            }
        });
    } catch (error) {
        removeBell();
    }
}

async function loadMyRequests() {
    const list = document.querySelector('[data-my-requests]');
    if (!list || !app.currentUser) {
        return;
    }
    try {
        const data = await apiFetch('user/book_requests.php');
        app.csrfToken = data.csrf_token || app.csrfToken;
        const requests = data.requests || [];
        list.innerHTML = requests.length
            ? requests.slice(0, 10).map((item) => `
                <div class="request-row">
                    <div><strong>${escapeHtml(item.title)}</strong><small>${escapeHtml(item.author)}</small></div>
                    <span class="status-pill ${escapeHtml(item.status)}">${escapeHtml(item.status)}</span>
                </div>`).join('')
            : '<p class="muted">No requests yet.</p>';
    } catch (error) {
        list.innerHTML = `<p class="muted">${escapeHtml(error.message)}</p>`;
    }
}

async function loadAdminPendingBadge() {
    const badge = document.querySelector('[data-admin-pending-count]');
    if (!badge || !app.currentUser || app.currentUser.role !== 'admin') {
        return;
    }
    try {
        const data = await apiFetch('admin/book_requests.php');
        const pending = Number(data.pending_count || 0);
        if (pending > 0) {
            badge.textContent = pending > 9 ? '9+' : String(pending);
            badge.hidden = false;
        } else {
            badge.hidden = true;
        }
    } catch (error) {
        badge.hidden = true;
    }
}

async function loadAdminRequests() {
    const manager = document.querySelector('#requestManager');
    if (!manager) {
        return;
    }
    try {
        const data = await apiFetch('admin/book_requests.php');
        app.csrfToken = data.csrf_token || app.csrfToken;
        const requests = data.requests || [];
        const tabCount = document.querySelector('#requestsTabCount');
        if (tabCount) {
            tabCount.textContent = Number(data.pending_count || 0) > 0 ? `(${data.pending_count})` : '';
        }
        const books = app.adminBooks || [];
        manager.innerHTML = requests.length
            ? requests.map((item) => `
                <div class="admin-request-row" data-request-id="${Number(item.id)}">
                    <div class="admin-request-meta">
                        <strong>${escapeHtml(item.title)}</strong>
                        <small>${escapeHtml(item.author)} &middot; ${escapeHtml(item.user_email || 'reader')} &middot; ${escapeHtml(new Date(item.created_at).toLocaleDateString())}</small>
                    </div>
                    <span class="status-pill ${escapeHtml(item.status)}">${escapeHtml(item.status)}</span>
                    ${item.status === 'pending' ? `
                        <select data-fulfill-book-id aria-label="Link uploaded book (optional)">
                            <option value="">Auto-match / no link</option>
                            ${books.map((book) => `<option value="${Number(book.id)}">${escapeHtml(book.title)} &middot; ${escapeHtml(book.author || '')}</option>`).join('')}
                        </select>
                        <button type="button" class="button-link" data-fulfill-request="${Number(item.id)}">Fulfill</button>
                        <button type="button" class="secondary-button" data-reject-request="${Number(item.id)}">Reject</button>
                    ` : `
                        <button type="button" class="secondary-button" data-reopen-request="${Number(item.id)}">Reopen</button>
                    `}
                    <button type="button" class="danger-button" data-delete-request="${Number(item.id)}">Delete</button>
                </div>`).join('')
            : '<p class="muted">No book requests yet.</p>';
    } catch (error) {
        manager.innerHTML = `<p class="muted">${escapeHtml(error.message)}</p>`;
    }
}

function initRequestBookPanel() {
    document.addEventListener('click', (event) => {
        const bellToggle = event.target.closest('[data-bell-toggle]');
        if (!bellToggle) {
            const dropdown = event.target.closest('[data-notification-dropdown]');
            const gotoRequests = event.target.closest('[data-goto-requests]');
            if (gotoRequests) {
                event.preventDefault();
                closeBellDropdown();
                document.querySelector('[data-admin-tab="requests"]')?.click();
                document.querySelector('[data-admin-panel="requests"]')?.scrollIntoView({ behavior: 'smooth' });
                return;
            }
            if (!dropdown) {
                closeBellDropdown();
            }
        }

        const toggle = event.target.closest('[data-request-book-toggle]');
        if (toggle) {
            const wrap = document.querySelector('[data-request-book-wrap]');
            if (wrap) {
                wrap.hidden = !wrap.hidden;
                if (!wrap.hidden) {
                    loadMyRequests();
                }
            }
        }
    });

    document.addEventListener('submit', async (event) => {
        const form = event.target.closest('#requestBookForm');
        if (!form) {
            return;
        }
        event.preventDefault();
        const status = form.querySelector('[data-request-status]');
        const formData = new FormData(form);
        const title = String(formData.get('title') || '').trim();
        const author = String(formData.get('author') || '').trim();
        if (status) {
            status.textContent = 'Sending...';
        }
        try {
            const data = await apiFetch('user/book_requests.php', {
                method: 'POST',
                body: JSON.stringify({ title, author, csrf_token: app.csrfToken }),
            });
            if (status) {
                status.textContent = data.message || 'Request sent.';
            }
            form.reset();
            await loadMyRequests();
        } catch (error) {
            if (status) {
                status.textContent = error.message;
            }
        }
    });

    document.querySelector('#refreshRequests')?.addEventListener('click', loadAdminRequests);

    document.querySelector('#requestManager')?.addEventListener('click', async (event) => {
        const notice = document.querySelector('#adminNotice');
        const fulfillBtn = event.target.closest('[data-fulfill-request]');
        const rejectBtn = event.target.closest('[data-reject-request]');
        const reopenBtn = event.target.closest('[data-reopen-request]');
        const deleteBtn = event.target.closest('[data-delete-request]');
        const button = fulfillBtn || rejectBtn || reopenBtn || deleteBtn;
        if (!button) {
            return;
        }
        const row = button.closest('[data-request-id]');
        const id = Number(row?.dataset.requestId || 0);
        try {
            let payload;
            if (fulfillBtn) {
                const select = row?.querySelector('[data-fulfill-book-id]');
                payload = { action: 'fulfill', id, book_id: Number(select?.value || 0), csrf_token: app.csrfToken };
            } else if (rejectBtn) {
                payload = { action: 'reject', id, csrf_token: app.csrfToken };
            } else if (reopenBtn) {
                payload = { action: 'reopen', id, csrf_token: app.csrfToken };
            } else {
                const data = await apiFetch('admin/book_requests.php', {
                    method: 'DELETE',
                    body: JSON.stringify({ id, csrf_token: app.csrfToken }),
                });
                showNotice(notice, data.message);
                await loadAdminRequests();
                await loadAdminBell();
                return;
            }
            const data = await apiFetch('admin/book_requests.php', { method: 'POST', body: JSON.stringify(payload) });
            showNotice(notice, data.message);
            await loadAdminRequests();
            await loadAdminBell();
        } catch (error) {
            showNotice(notice, error.message, 'error');
        }
    });
}

document.addEventListener('DOMContentLoaded', async () => {
    initThemeToggle();
    initSearchToggle();
    initUserPanel();
    initRequestBookPanel();
    initHomeSearch();
    initCoverPreview();
    initShelfScrolling();
    initScrollTop();
    initReaderActions();
    initProfileInteractions();

    await initReaderSession();
    await initNotifications();
    await loadHome();
    await initGenreSearch();
    await loadBookDetail();
    await loadReader();
    await initReaderLogin();
    await loadProfile();
    await loadLibrary();
    await loadSetup();
    await initLogin();
    await loadAdminDashboard();
    initAdminForms();
    initAdminTabs();
    initSectionForms();
    initGenreForms();
});
