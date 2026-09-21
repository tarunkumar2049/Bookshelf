# Bookshelf Web Reader Platform

A MangaDex-inspired web reader built with static HTML, CSS, vanilla JavaScript, PHP backend APIs, MySQL, and no frameworks.

The frontend is pure `.html`, `.css`, and `.js`. PHP is used only for backend/API work: database queries, admin sessions, password verification, CSRF checks, and file uploads.

## Features

- Static HTML pages for browse, book detail, reader, admin login, admin dashboard, and setup.
- Vanilla JavaScript renders UI and talks to PHP APIs with `fetch()`.
- Reader login/register with profile, dashboard stats, library, wishlist, and reading history.
- Reader email OTP verification for unverified accounts.
- Admin-only login with `password_hash()` and `password_verify()`.
- Admin book deletion with cascading chapter/page cleanup.
- Admin PDF upload as an optional book file.
- MySQL schema with `users`, `books`, `chapters`, `pages`, `reading_history`, and `wishlists`.
- Prepared statements through PDO.
- CSRF tokens on admin and setup requests.
- Book grid, title search, pagination, book detail pages, and vertical chapter reader.
- Image pages with native lazy loading.
- Text pages saved as `.txt` files and returned by the reader API.
- Upload validation for JPG, PNG, WEBP, GIF, and PDF files.
- Responsive light Bookshelf UI.

## Folder Structure

```text
/
|-- admin/
|   |-- index.html
|   `-- login.html
|-- api/
|   |-- admin/
|   |   |-- books.php
|   |   |-- chapters.php
|   |   |-- dashboard.php
|   |   |-- pages.php
|   |   `-- session.php
|   |-- book.php
|   |-- books.php
|   |-- chapter.php
|   |-- user/
|   |   |-- history.php
|   |   |-- profile.php
|   |   |-- session.php
|   |   `-- wishlist.php
|   `-- setup_admin.php
|-- assets/
|   |-- css/style.css
|   |-- images/
|   `-- js/app.js
|-- includes/
|   |-- api.php
|   |-- auth.php
|   |-- config.php
|   |-- db.php
|   `-- functions.php
|-- uploads/
|   |-- books/
|   |   `-- pdfs/
|   `-- chapters/
|-- book.html
|-- index.html
|-- library.html
|-- login.html
|-- profile.html
|-- reader.html
|-- signup.html
|-- schema.sql
`-- setup_admin.html
```

## API Overview

- `GET api/books.php?q=&page=` lists books.
- `GET api/book.php?id=` returns one book and its chapters.
- `GET api/chapter.php?chapter_id=` returns chapter pages and next/previous chapter IDs.
- `GET/POST/DELETE api/user/session.php` handles reader session, login, register, and logout.
- `GET api/user/profile.php` returns dashboard, reading history, and wishlist data.
- `POST api/user/history.php` records the latest chapter a reader opened.
- `GET/POST api/user/wishlist.php` checks and toggles wishlist books.
- `GET api/setup_admin.php` returns setup lock state and CSRF token.
- `POST api/setup_admin.php` creates the first admin.
- `GET api/admin/session.php` returns admin session state and CSRF token.
- `POST api/admin/session.php` logs in.
- `DELETE api/admin/session.php` logs out.
- `GET api/admin/dashboard.php` returns admin dropdown/list data.
- `POST api/admin/books.php` adds a book.
- `DELETE api/admin/books.php` deletes a book and its uploaded files.
- `POST api/admin/chapters.php` adds a chapter.
- `POST api/admin/pages.php` uploads image or text pages.

## XAMPP/WAMP Setup

1. Copy this project folder into your web root:
   - XAMPP: `C:\xampp\htdocs\bookshelf-reader`
   - WAMP: `C:\wamp64\www\bookshelf-reader`

2. Start Apache and MySQL from XAMPP or WAMP.

3. Open phpMyAdmin and import `schema.sql`.

   If you already imported the older schema before reader accounts existed, import `migration_reader_features.sql` once.
   If your database already has reader features but not PDF uploads, import `migration_pdf_books.sql` once.
   If your database already has PDF uploads but PDF books do not appear in Library after reading, import `migration_pdf_history.sql` once.
   If your database already existed before profile pictures and OTP, import `migration_profile_otp.sql` once.

4. Open `includes/config.php` and confirm your database settings:

```php
const DB_HOST = '127.0.0.1';
const DB_NAME = 'bookshelf_reader';
const DB_USER = 'root';
const DB_PASS = '';
```

5. Visit the static setup page:

```text
http://localhost/bookshelf-reader/setup_admin.html
```

Create the first admin account. The backend setup API locks after one admin exists.

6. Visit:

```text
http://localhost/bookshelf-reader/admin/login.html
```

Log in with the admin account you just created.

7. Use the dashboard in this order:
   - Add a book.
   - Optionally attach a PDF in the "Book PDF" field.
   - Add a chapter for that book.
   - Upload image pages or a text page for that chapter.
   - Delete books from the "All books" panel when needed.

8. Browse the public site:

```text
http://localhost/bookshelf-reader/index.html
```

## Notes

- Uploaded files are stored under `uploads/books`, `uploads/books/pdfs`, and `uploads/chapters`.
- Page file paths are stored in the `pages.content_path` column.
- If you use Apache, `uploads/.htaccess` blocks directory listing and PHP execution in uploads.
- If uploads fail, make sure the `uploads` folder is writable by Apache.
- OTP emails use PHP `mail()`. On local XAMPP/WAMP, configure SMTP/mail first or OTP sending will fail.
- In production, restrict or remove `setup_admin.html` and `api/setup_admin.php` after initial setup.
