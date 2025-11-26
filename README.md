<p align="center">
  <img src="https://whisper.money/images/og_whisper_money.png" alt="Whisper Money" width="100%">
</p>

# Whisper Money

<img src="https://github.com/whisper-money/whisper-money/actions/workflows/ci.yml/badge.svg" />

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

This project is licensed under the **Creative Commons Attribution-NonCommercial 4.0 International License (CC BY-NC 4.0)**.

You are free to:

- **Share** — copy and redistribute the material in any medium or format
- **Adapt** — remix, transform, and build upon the material

Under the following terms:

- **Attribution** — You must give appropriate credit, provide a link to the license, and indicate if changes were made
- **NonCommercial** — You may not use the material for commercial purposes

For more details, see the [full license](https://creativecommons.org/licenses/by-nc/4.0/).
