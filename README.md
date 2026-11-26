# Pixelforge

A Laravel, Vue and Inertia workspace for generating images and tracking usage in USD. Start with PHP 8.4, Composer, Node 22+, and PostgreSQL 16. The session authentication controllers come from the [official Vue starter kit](https://github.com/laravel/vue-starter-kit/tree/v1.0.2).

1. Create separate PostgreSQL databases named `pixelforge` and `pixelforge_test`. Give the application user access to both.
2. Copy `.env.example` to `.env`. Set the local database host, port, username and password.
3. Run `composer install`, `npm ci`, `php artisan key:generate`, and `php artisan migrate`.
4. Start `php artisan serve` and `npm run dev` in separate terminals. Visit the address printed by Artisan.

For verification run `npm run lint`, `npm run types`, `npm run build`, `vendor/bin/pint --test`, and `php artisan test`. PHPUnit uses the separate `pixelforge_test` database.
