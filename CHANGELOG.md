# Changelog

## [0.1.2](https://github.com/whisper-money/whisper-money/compare/v0.1.1...v0.1.2) (2026-01-07)


### Bug Fixes

* issue on filters when no label created ([cb1d6a2](https://github.com/whisper-money/whisper-money/commit/cb1d6a230f0c1c0734e5267a5a4d2753f4b91cff))

## 0.1.1 (2026-01-05)


### Bug Fixes

* add SSR guards to localStorage/sessionStorage access ([3b56e24](https://github.com/whisper-money/whisper-money/commit/3b56e2444713f922bccc2790f676dab167758500))
* add SyncProvider to SSR entry point ([3177fa3](https://github.com/whisper-money/whisper-money/commit/3177fa3519e2728c813f7532bfc7c65b603398b7))
* app logo icon auto of the dashboard ([e813849](https://github.com/whisper-money/whisper-money/commit/e813849e7ba352f1ad0100fd77a41d770bed1968))
* apply border radius to visible bar segments in stacked chart ([413f83f](https://github.com/whisper-money/whisper-money/commit/413f83f96163b1ae6ce5e62d810fbdaccae480d6))
* asd key element to accounts index page ([8eab41a](https://github.com/whisper-money/whisper-money/commit/8eab41ac89747437f3afcd27e90012ddc8d1e3dd))
* auto-regenerate APP_KEY if invalid format (missing base64: prefix) ([797cb06](https://github.com/whisper-money/whisper-money/commit/797cb06f86037a1f89b0875aa5ed38307c70ed57))
* automated rules broken and now they work in batches ([890593d](https://github.com/whisper-money/whisper-money/commit/890593d9674d0aacf9f4491a49e36ca6884afa9b))
* Automated rules with labels ([#32](https://github.com/whisper-money/whisper-money/issues/32)) ([bf0c9ae](https://github.com/whisper-money/whisper-money/commit/bf0c9ae989f2543b7093630bfa0723c669689b3b))
* bulk action bar style ([045c7a5](https://github.com/whisper-money/whisper-money/commit/045c7a5752081eb0b1ba9cbe5744eab13ad2d7c5))
* **category-combobox:** Improve UI responsiveness and truncate category names ([2cecd01](https://github.com/whisper-money/whisper-money/commit/2cecd014e0cff0aefe70ace625baacbf58255f6d))
* **charts:** mobile ui, and desktop tooltips ([818a49e](https://github.com/whisper-money/whisper-money/commit/818a49e79956f16d71e01736593cec762bb67a46))
* deploy ci ([d4410a6](https://github.com/whisper-money/whisper-money/commit/d4410a67fe81e0e409138ab7913b7e3787604e66))
* increase nginx buffer sizes ([a87b36d](https://github.com/whisper-money/whisper-money/commit/a87b36de3f4416abacb232976ca3d113592d32fa))
* make encryption key storage SSR-safe to prevent 502 errors ([0fcc66e](https://github.com/whisper-money/whisper-money/commit/0fcc66e25d2eba710111e0da2bed64bbe5ee9110))
* make useIsMobile hook and utility functions SSR-safe ([40762bc](https://github.com/whisper-money/whisper-money/commit/40762bc528447f42b39ca5121a9047f376ffbe6b))
* migration history ([b52e2de](https://github.com/whisper-money/whisper-money/commit/b52e2de9870294e0aa5a8da5f047a51930d52167))
* **mobile:** account chart ([14a9343](https://github.com/whisper-money/whisper-money/commit/14a9343c1d5142beea7bc9dbfa130510fd5addbc))
* normalize transaction_date to YYYY-MM-DD for duplicate detection ([#4](https://github.com/whisper-money/whisper-money/issues/4)) ([7492b2e](https://github.com/whisper-money/whisper-money/commit/7492b2e7360f6b8e53be891ce55a74e0b4fa6c66))
* re-enable ssr for all routes after issue is fixed ([1d96f5d](https://github.com/whisper-money/whisper-money/commit/1d96f5dc63b6a8abf6107f683ceb9c73fc8763b1))
* rong schedule import ([c684695](https://github.com/whisper-money/whisper-money/commit/c684695008cbf180cc4a621b9fc325ee8669e5da))
* **sync:** make transaction creation idempotent ([#38](https://github.com/whisper-money/whisper-money/issues/38)) ([3cbe0a7](https://github.com/whisper-money/whisper-money/commit/3cbe0a7879df68affe62944901dfc2054855fbf1))
* toast on mobile ([716e21b](https://github.com/whisper-money/whisper-money/commit/716e21b219a31a07b8e6cf859567b45e15d1a485))
* transaction list on account page ([ce09f32](https://github.com/whisper-money/whisper-money/commit/ce09f32a9290561363169ec7a7d3b85999aaf35e))
* **TransactionFilters:** Update badge styling for uncategorized selection ([a2d7af2](https://github.com/whisper-money/whisper-money/commit/a2d7af27898040dcfbb7287ba8803edbf28db14d))
* **transactions:** Decrypt account names for automation rule evaluation ([323b738](https://github.com/whisper-money/whisper-money/commit/323b7386c1e5e1cfbf32258d7430b2e3686e4b4c))
* **transactions:** We were creating transactions with numberic ID instead of UUID v7 ([52e1a7b](https://github.com/whisper-money/whisper-money/commit/52e1a7bd955d0018ba5a2cfa761e6c58aaa81d3f))
* use direct PDO connection test for MySQL readiness check ([a7ee776](https://github.com/whisper-money/whisper-money/commit/a7ee776af791a92f42fada35965476b9d903b50a))
* use markdown to send user lead invitation mail ([1e9566a](https://github.com/whisper-money/whisper-money/commit/1e9566a289125133d23bba7a7ed2102e126b5a08))
* wrap SSR app with EncryptionKeyProvider ([770f091](https://github.com/whisper-money/whisper-money/commit/770f091b9b4509e0b5ca51ded1080b228594500e))
* wrong user menu text ([b2d1bcf](https://github.com/whisper-money/whisper-money/commit/b2d1bcf54c7061ab6cc2adb8182795eedd20233d))


### Features

* **.cursor:** Add whisper-money rule configuration ([e80647d](https://github.com/whisper-money/whisper-money/commit/e80647dc130f1c4b5f51857b27649229cf887701))
* **AccountBalanceSync:** Update existing balances and add new ones efficiently ([c2c6894](https://github.com/whisper-money/whisper-money/commit/c2c6894cb860e768fdb2c5ece746bf97129784db))
* Add account balance chart improvements and icons ([#5](https://github.com/whisper-money/whisper-money/issues/5)) ([5f149b4](https://github.com/whisper-money/whisper-money/commit/5f149b4bae7065f2c2aaa191941bdc3fa9dfe41e))
* Add bank selection to edit transaction dialog ([0473371](https://github.com/whisper-money/whisper-money/commit/0473371fce68f95cbce5aa3bf590253e56c7129d))
* Add Discord invite link to welcome page ([f3c0fa1](https://github.com/whisper-money/whisper-money/commit/f3c0fa1355921a2dceab1e1dd5df5e0cd5527c7f))
* Add financial models and seeders ([635cde0](https://github.com/whisper-money/whisper-money/commit/635cde021b59c9078e72882327c17d500503d22a))
* Add import transactions button to transactions page ([e5a77a9](https://github.com/whisper-money/whisper-money/commit/e5a77a9aca92cc8b12e09d24402ef3d84a223b0e))
* add multiple chart view modes for net worth evolution ([#37](https://github.com/whisper-money/whisper-money/issues/37)) ([c5df59c](https://github.com/whisper-money/whisper-money/commit/c5df59c285b253ac5f4bbef36a4523fe885491af))
* Add new category icons and colors ([c339105](https://github.com/whisper-money/whisper-money/commit/c33910587585ea8da4dfde4b79aa14498fc58692))
* Add privacy mode to hide monetary amounts ([#28](https://github.com/whisper-money/whisper-money/issues/28)) ([8811afb](https://github.com/whisper-money/whisper-money/commit/8811afbad8f5ef2dae0ebb8562a66d8ae9aa3938))
* add transaction labels feature ([#24](https://github.com/whisper-money/whisper-money/issues/24)) ([4b5d65b](https://github.com/whisper-money/whisper-money/commit/4b5d65ba03371c7b85bab0b64ec4dc8d19b015b3))
* add version tracking with git tags and changelog ([db81c9b](https://github.com/whisper-money/whisper-money/commit/db81c9b88861dd60eef97eba035cf03ca1a7d6a1))
* **auth:** Add key clearing on login ([3795e46](https://github.com/whisper-money/whisper-money/commit/3795e46d4fb11e228524f2e8557cd931a315db8e))
* **automation:** Add re-evaluate all transactions functionality ([e937a86](https://github.com/whisper-money/whisper-money/commit/e937a8647dbe69fbd93ea2b5ddad44bbe7ba4a18))
* **automation:** Add sync functionality to automation rule dialogs ([e009abb](https://github.com/whisper-money/whisper-money/commit/e009abbee19252bab2dbcc18170c54870df9f5b9))
* **category:** Update default categories list and sorting logic ([73d847f](https://github.com/whisper-money/whisper-money/commit/73d847f38b35e3c25a3f890574e42b5210d12d67))
* centralize pricing config with multiple plans support ([#20](https://github.com/whisper-money/whisper-money/issues/20)) ([58b9343](https://github.com/whisper-money/whisper-money/commit/58b934333f55a43372fefd634cde05a3b0109859))
* Configure Resend email integration ([#34](https://github.com/whisper-money/whisper-money/issues/34)) ([3c22453](https://github.com/whisper-money/whisper-money/commit/3c22453fc611a109d69ed3c6bff2e6fb12163aba))
* **Docker:** Add Bun installation and update build process ([4379239](https://github.com/whisper-money/whisper-money/commit/43792392b4e9b3213b39348eeaa002e13348df9a))
* **Docker:** Add Wayfinder route generation and update asset build process ([a13e7fd](https://github.com/whisper-money/whisper-money/commit/a13e7fd538628b0ebc1c1b0a9893a5b36b2b32d2))
* **Docker:** Optimize build process by removing unnecessary steps and adjusting environment variables ([732775e](https://github.com/whisper-money/whisper-money/commit/732775e47ef92f01f0449b2cad1e337627bd5a4b))
* **Docker:** Replace pnpm with Bun for Node.js package management ([5b45006](https://github.com/whisper-money/whisper-money/commit/5b450067eb51e003e0074a44276587d7afe8514c))
* **Docker:** Replace pnpm with bun for package management and build process ([b4b891f](https://github.com/whisper-money/whisper-money/commit/b4b891f204a7bf8fe1f1b9c036cfee6052a18bd4))
* **encrypted-text:** Add animation and random character generation ([7d8474f](https://github.com/whisper-money/whisper-money/commit/7d8474f6b81f032ac4585fceb293c9d5e6e5594d))
* **encrypted-text:** Improve encryption UI with dynamic masking and loading state ([ff186a4](https://github.com/whisper-money/whisper-money/commit/ff186a4887c715b10205508d41f453df90201b26))
* Implement drip email campaign system ([#35](https://github.com/whisper-money/whisper-money/issues/35)) ([46c5b13](https://github.com/whisper-money/whisper-money/commit/46c5b137392a333c98ebcb6d3435556b52a18994))
* **import-transactions-drawer:** Add json-logic-js dependency and improve import logic ([1df3bad](https://github.com/whisper-money/whisper-money/commit/1df3bad3c3d27e4fe224277c4aedb8872fb6ba25))
* **lucide-react:** Add custom icons to Toaster component ([573b2fd](https://github.com/whisper-money/whisper-money/commit/573b2fdb0a13cd2c2064996c8660a98ed97a60c2))
* **queue:** Implement queueable email jobs with rate limiting ([3d0d6c8](https://github.com/whisper-money/whisper-money/commit/3d0d6c8bef11e06e3a39b7a8e9dbc4fb166657e7))
* **react:** add authentication check in SyncProvider ([48bce81](https://github.com/whisper-money/whisper-money/commit/48bce81d9a23f894008bdfaa9c6876431f0c293e))
* Remove console.log and add padding to components ([c1f99fe](https://github.com/whisper-money/whisper-money/commit/c1f99fedd6255621e3c9a301d79bbe3968908aea))
* Replace Input with Textarea for editable descriptions ([2b6acf4](https://github.com/whisper-money/whisper-money/commit/2b6acf49d8770c74538e0f8664d9e88b4ae0b63e))
* **settings:** Update account management UI and add sync functionality ([ab63edd](https://github.com/whisper-money/whisper-money/commit/ab63edde2b23f1a9055fcce7b456a4825251cebb))
* **shared:** Add CategoryCombobox component ([57879bb](https://github.com/whisper-money/whisper-money/commit/57879bb7118850ae03ed2059dc5b775c29f5885d))
* **sync:** Add sync functionality for accounts, banks, categories, and status button ([9256148](https://github.com/whisper-money/whisper-money/commit/9256148961201ba52fe93d29517fb6c0dbf24147))
* **traefik:** Add secure headers middleware to WhisperMoney service ([242be5f](https://github.com/whisper-money/whisper-money/commit/242be5f415be11696fafdf4db68f4dafae964c66))
* **TransactionController:** Add store method for creating transactions ([c1fbd4d](https://github.com/whisper-money/whisper-money/commit/c1fbd4d09fe67a092ad45e49b97ce7a172cf9913))
* **TransactionSyncController:** Sort transactions by transaction_date and updated_at ([41f5c64](https://github.com/whisper-money/whisper-money/commit/41f5c6485c11934e69c6efab2868ea541e2856d4))
* **ui:** Implement virtual scrolling for DataTable component ([07ca633](https://github.com/whisper-money/whisper-money/commit/07ca63347e9bae5bc59b8f0f8073e64da1df68f4))
* **ui:** Improve chart tooltip content rendering and calculation ([d04b6a0](https://github.com/whisper-money/whisper-money/commit/d04b6a0174910f5e8eb4dce491805e60d7e67c04))
* update date formatting logic in transaction components ([d13ecc2](https://github.com/whisper-money/whisper-money/commit/d13ecc2722509501d018b27a3b4dd83e7ab4351b))
* Update encryption key button icon based on state ([08baf3b](https://github.com/whisper-money/whisper-money/commit/08baf3b19a8d4a631d2942a31e47071be68a128c))
* Update ProfileController to include two-factor authentication settings ([e21c9cc](https://github.com/whisper-money/whisper-money/commit/e21c9cc3a89fdb8ac84bea49e4a1f6963ab7542e))
* Update welcome page title to focus on understanding finances ([3ac7102](https://github.com/whisper-money/whisper-money/commit/3ac71025013ed1c8da713c753b9ef2bd3e050eee))
* **use-dashboard-data:** Add conditional formatting for current year dates ([525e770](https://github.com/whisper-money/whisper-money/commit/525e7709cc8c92f90ece1bfce572e8434de60b15))
* **welcome:** Add GitHub link and refactor auth buttons ([2ab362d](https://github.com/whisper-money/whisper-money/commit/2ab362dc5db7fa14104232cce283e53f5b658761))


### Reverts

* Revert "swap horizon -> queue:work on mysql" ([03880ca](https://github.com/whisper-money/whisper-money/commit/03880ca4920eba081d33147ceedd982f81c1a65b))

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2025-12-28

### Added

- Initial release with end-to-end encrypted finance tracking
- Account management and bank sync via GoCardless
- Transaction categorization and labeling
- Net worth and account balance charts with multiple view modes
- PWA support for mobile installation
