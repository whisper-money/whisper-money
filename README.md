<p align="center"><a href="https://www.producthunt.com/products/whisper-money?embed=true&utm_source=badge-featured&utm_medium=badge&utm_source=badge-whisper&#0045;money" target="_blank"><img src="https://api.producthunt.com/widgets/embed-image/v1/featured.svg?post_id=1042250&theme=light&t=1764232132380" alt="Whisper&#0032;Money - The&#0032;most&#0032;secure&#0032;way&#0032;to&#0032;understand&#0032;your&#0032;finances | Product Hunt" style="width: 250px; height: 54px;" width="250" height="54" /></a></p>

<p align="center">
  <img src="https://whisper.money/images/og_whisper_money.png" alt="Whisper Money" width="100%">
</p>

# Whisper Money

<img src="https://github.com/whisper-money/whisper-money/actions/workflows/ci.yml/badge.svg" /> [![CC BY-NC 4.0][cc-by-nc-shield]][cc-by-nc]

**The most secure way to understand your finances.**

Whisper Money is a privacy-first personal finance application that helps you track, categorize, and understand your spending—all while keeping your financial data encrypted and secure.

## Features

- 🔐 **End-to-end encryption** — Your financial data stays private
- 🏦 **Bank account management** — Track multiple accounts in one place
- 📊 **Transaction categorization** — Automatic and manual categorization
- 🤖 **Automation rules** — Set up rules to auto-categorize transactions
- 📈 **Financial insights** — Understand your spending patterns

## Tech Stack

- **Backend:** Laravel 12, PHP 8.4
- **Frontend:** React 19, Inertia.js v2, TypeScript
- **Styling:** Tailwind CSS v4
- **Database:** MySQL
- **Cache/Queue:** Redis
- **Testing:** Pest v4

## Running Locally

### Prerequisites

- Docker & Docker Compose
- Composer
- Node.js / Bun

### Setup

1. **Clone the repository:**

```bash
git clone https://github.com/your-username/whisper_money.git
cd whisper_money
```

2. **Copy the environment file:**

```bash
cp .env.example .env
```

3. **Start the Docker services:**

```bash
docker compose up -d
```

4. **Install dependencies and setup the application:**

```bash
composer setup
```

5. **Start the development server:**

```bash
composer run dev
```

This will concurrently start:

- PHP development server
- Queue worker
- Log viewer (Pail)
- Vite dev server

The application will be available at `https://whispermoney.test`.

## License

This work is licensed under a
[Creative Commons Attribution-NonCommercial 4.0 International License][cc-by-nc].

[cc-by-nc]: https://creativecommons.org/licenses/by-nc/4.0/
[cc-by-nc-image]: https://licensebuttons.net/l/by-nc/4.0/88x31.png
[cc-by-nc-shield]: https://img.shields.io/badge/License-CC%20BY--NC%204.0-lightgrey.svg
