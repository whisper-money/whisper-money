# Changelog

## [0.1.13](https://github.com/whisper-money/whisper-money/compare/v0.1.12...v0.1.13) (2026-02-25)


### Bug Fixes

* **budgets:** handle refunds correctly in budget spending calculations ([#152](https://github.com/whisper-money/whisper-money/issues/152)) ([f2a7f95](https://github.com/whisper-money/whisper-money/commit/f2a7f955e67465bb415685d9c17ecab213f7decf))
* improve connection error message contrast in dark mode ([#155](https://github.com/whisper-money/whisper-money/issues/155)) ([e718f5d](https://github.com/whisper-money/whisper-money/commit/e718f5df5c5d996ff867081f53a64d8cc9259e78))
* **open-banking:** use net_amounts for Indexa Capital invested amount calculation ([#156](https://github.com/whisper-money/whisper-money/issues/156)) ([ae2a8c0](https://github.com/whisper-money/whisper-money/commit/ae2a8c011831f48daa0433a415db3337ce445e86))


### Features

* **open-banking:** add update credentials flow for API-key connections ([#154](https://github.com/whisper-money/whisper-money/issues/154)) ([690be20](https://github.com/whisper-money/whisper-money/commit/690be20f216c7e000032ffb8dc0d68e4046d5632))
* Update facehash and enable blink ([2550339](https://github.com/whisper-money/whisper-money/commit/255033999d1bef5d4ae28e5ddb0ebf4f59478639))
* use testcontainers for isolated MySQL in test runs ([#153](https://github.com/whisper-money/whisper-money/issues/153)) ([e4243c2](https://github.com/whisper-money/whisper-money/commit/e4243c2eaac5dd1fc59bb132cb51ab71712062ad))

## [0.1.12](https://github.com/whisper-money/whisper-money/compare/v0.1.10...v0.1.12) (2026-02-24)


### Bug Fixes

* Pricing table on dark scheme ([faddd59](https://github.com/whisper-money/whisper-money/commit/faddd59537903572109033cb2112eb0b6504d86a))


### Features

* enable invested amount tracking for savings accounts ([#142](https://github.com/whisper-money/whisper-money/issues/142)) ([0a9ca5b](https://github.com/whisper-money/whisper-money/commit/0a9ca5b606809e1772884887534317d7e86cfd8e))
* investment benefits — show gains/losses on investment accounts ([#140](https://github.com/whisper-money/whisper-money/issues/140)) ([299b8a5](https://github.com/whisper-money/whisper-money/commit/299b8a56d87f8217a9d5ce5a0916361a751d5a94))


### Performance Improvements

* **accounts:** replace client-side API calls with Inertia deferred prop ([#144](https://github.com/whisper-money/whisper-money/issues/144)) ([ce9574a](https://github.com/whisper-money/whisper-money/commit/ce9574aa147067447a870bf4d4f1347b7d81c08b))
* **dashboard:** optimize query performance and eliminate redundant requests ([#146](https://github.com/whisper-money/whisper-money/issues/146)) ([ae81e20](https://github.com/whisper-money/whisper-money/commit/ae81e20a66285ccda6e2a7d22b7ea0f683f0ffb4))
* make banking syncs incremental on subsequent runs ([#141](https://github.com/whisper-money/whisper-money/issues/141)) ([d48fea1](https://github.com/whisper-money/whisper-money/commit/d48fea15b2c48e4f4647d7762569774d42e3a87d))

## [0.1.10](https://github.com/whisper-money/whisper-money/compare/v0.1.9...v0.1.10) (2026-02-20)


### Bug Fixes

* Accounts name on settings/account ([202835f](https://github.com/whisper-money/whisper-money/commit/202835f76e6741ba0bf70c25b14fa1f63ec7ac94))
* Add gap between filter/create button on mobile settings pages ([#115](https://github.com/whisper-money/whisper-money/issues/115)) ([726bce6](https://github.com/whisper-money/whisper-money/commit/726bce61ef8e1d923b49a576d3fcccad31e1adc1))
* Automerge PR's where not triggering CI on main branch ([ab160ae](https://github.com/whisper-money/whisper-money/commit/ab160ae4890371a9100b6cd89cbce2d0a09180d2))
* Budget period not found on last day of period ([#91](https://github.com/whisper-money/whisper-money/issues/91)) ([00b2ca7](https://github.com/whisper-money/whisper-money/commit/00b2ca7c55d947d95c9582ce2039a91376f83db9))
* **cashflow:** prevent breakdown cards overflow on mobile ([#139](https://github.com/whisper-money/whisper-money/issues/139)) ([c03f576](https://github.com/whisper-money/whisper-money/commit/c03f5767585ce084945da00bbf3af902dce5d123))
* **charts:** use settings popover for chart controls on mobile ([#137](https://github.com/whisper-money/whisper-money/issues/137)) ([880b276](https://github.com/whisper-money/whisper-money/commit/880b27675cd1428fd6194fa7d0a058f398817079))
* console error ([a76826b](https://github.com/whisper-money/whisper-money/commit/a76826bd62e213537c3308bb0678abe9c40a54b3))
* Console log errors with Charts ([48b4b7b](https://github.com/whisper-money/whisper-money/commit/48b4b7bd01d4c9f80b3de98048746c537719d2af))
* Delete pending connection and show toast on cancelled bank authorization ([#111](https://github.com/whisper-money/whisper-money/issues/111)) ([c7f3f1a](https://github.com/whisper-money/whisper-money/commit/c7f3f1a9788d33f324028aabcad19238f1c00ec3))
* Disable email verification on dev/local ([1b0f3ba](https://github.com/whisper-money/whisper-money/commit/1b0f3ba24dc2c18fcd6c43abcb7c42c6184ec6ea))
* Discord link ([d7f0084](https://github.com/whisper-money/whisper-money/commit/d7f00843380042ac121e50eb94deb0ea86470f55))
* Header on iOS ([1d669b4](https://github.com/whisper-money/whisper-money/commit/1d669b44ca9e54247f66615cf11e5647aa2b2327))
* Hide transaction checkboxes on mobile ([#109](https://github.com/whisper-money/whisper-money/issues/109)) ([abd7a2f](https://github.com/whisper-money/whisper-money/commit/abd7a2f9aa681aa19e091eeb6b3a161a71f1ae69))
* Install script improvements ([da328ef](https://github.com/whisper-money/whisper-money/commit/da328efe7925c8fbb092579415ee66dfa2903891))
* Missing import ([b3103d4](https://github.com/whisper-money/whisper-money/commit/b3103d4a61e20a33003c4dc436ab34bf9180fa0f))
* Onboarding, account not shown on the import drawer ([#121](https://github.com/whisper-money/whisper-money/issues/121)) ([eeca437](https://github.com/whisper-money/whisper-money/commit/eeca437586b8f9f564782ea92bf85412eea28bb6))
* Prevent account card content overflow on long names ([#133](https://github.com/whisper-money/whisper-money/issues/133)) ([a2b1e91](https://github.com/whisper-money/whisper-money/commit/a2b1e91b49695c299ea8c437048e6c3d429e653f))
* Prevent automerge when CI checks have failed ([#95](https://github.com/whisper-money/whisper-money/issues/95)) ([6101cfd](https://github.com/whisper-money/whisper-money/commit/6101cfdfa022f3ecb78cc924e014667169f51d08)), closes [#94](https://github.com/whisper-money/whisper-money/issues/94) [#94](https://github.com/whisper-money/whisper-money/issues/94)
* Prevent re-syncing deleted bank transactions ([#114](https://github.com/whisper-money/whisper-money/issues/114)) ([d1ba189](https://github.com/whisper-money/whisper-money/commit/d1ba18932e80e858c8e928530a5d012747288b96))
* Small dashboard UI fix ([1500e5c](https://github.com/whisper-money/whisper-money/commit/1500e5cd9126bfd5be4e5e0c4e5767cb59d97c9a))
* Top spending categories bug ([e8e4f47](https://github.com/whisper-money/whisper-money/commit/e8e4f4780497780daf88e2c1a6abd69bf290a9a2))
* Top spending categories on mobile ([74ac346](https://github.com/whisper-money/whisper-money/commit/74ac346ca0c3b38a4b4b59a308a087fba78bc0e3))
* Top spending category must be 100% with always ([f31a44b](https://github.com/whisper-money/whisper-money/commit/f31a44bba2756a4111f3e5e2c3d1b7ae2c643124))
* Trigger sync on transactions drawer ([f88444f](https://github.com/whisper-money/whisper-money/commit/f88444fece957899245d0717414ac1efa345edb9))
* Use workflow_run trigger for automerge ([#89](https://github.com/whisper-money/whisper-money/issues/89)) ([dfd8bf8](https://github.com/whisper-money/whisper-money/commit/dfd8bf8092a666fe4e955a37a7c63de33d732ced))
* Welcome page mobile display for iOS ([#94](https://github.com/whisper-money/whisper-money/issues/94)) ([28f9432](https://github.com/whisper-money/whisper-money/commit/28f9432af4912344825007f463ae91480e336932))


### Features

* Add --user and --connection filters to banking:sync command ([#122](https://github.com/whisper-money/whisper-money/issues/122)) ([b9abf49](https://github.com/whisper-money/whisper-money/commit/b9abf49617c7a8ef2803fc9231e35a2695ef5004))
* Add 'Today' marker on budget spending chart ([#134](https://github.com/whisper-money/whisper-money/issues/134)) ([a0d19ae](https://github.com/whisper-money/whisper-money/commit/a0d19aef812024c9f218665b4d144d3ba7ba28f2))
* Add automerge workflow for labeled PRs ([#88](https://github.com/whisper-money/whisper-money/issues/88)) ([10bd7da](https://github.com/whisper-money/whisper-money/commit/10bd7da5dbc1c0dada7bc0b72375ad9cd3fd9be7))
* Add Binance integration ([#131](https://github.com/whisper-money/whisper-money/issues/131)) ([df9fc38](https://github.com/whisper-money/whisper-money/commit/df9fc385623a1ace173d4fbbc6e9a79ed93dc5ed))
* Add Bitpanda exchange integration ([#132](https://github.com/whisper-money/whisper-money/issues/132)) ([fe76c2e](https://github.com/whisper-money/whisper-money/commit/fe76c2e43d2aa32210292456bc9d9c50355f3c2b))
* Add daily balance chart with area visualization for account pages ([#135](https://github.com/whisper-money/whisper-money/issues/135)) ([126f7f7](https://github.com/whisper-money/whisper-money/commit/126f7f7e72e90ef0ae2862fd463136b70857e6ff))
* Add daily granularity toggle with area visualization to net worth chart ([#136](https://github.com/whisper-money/whisper-money/issues/136)) ([900cf41](https://github.com/whisper-money/whisper-money/commit/900cf41e317433eedd76e28c7e6b9846cb330d69))
* Add Indexa Capital integration ([#130](https://github.com/whisper-money/whisper-money/issues/130)) ([3f541ca](https://github.com/whisper-money/whisper-money/commit/3f541ca4d6376bc8a04d54851eccc7265060fe78))
* Add multi-currency conversion for net worth charts ([#138](https://github.com/whisper-money/whisper-money/issues/138)) ([b743cad](https://github.com/whisper-money/whisper-money/commit/b743cad8039167d30c2278156b707b170922ac5a))
* Add per-bank description formatter for bank-synced transactions ([#120](https://github.com/whisper-money/whisper-money/issues/120)) ([9242b3f](https://github.com/whisper-money/whisper-money/commit/9242b3fe5f14a03321a7bf4d1c9478f109f05ab6))
* Add previous period comparison to budget chart ([#93](https://github.com/whisper-money/whisper-money/issues/93)) ([9bbd91a](https://github.com/whisper-money/whisper-money/commit/9bbd91ac12d98c8919de9b15c56d841333ebbb18))
* Apply automation rules to bank-synced transactions ([#112](https://github.com/whisper-money/whisper-money/issues/112)) ([8ce0adf](https://github.com/whisper-money/whisper-money/commit/8ce0adf8aec1e297ba87ab2ce4b39c851241fae0))
* Bulk delete with type-to-confirm modal ([#110](https://github.com/whisper-money/whisper-money/issues/110)) ([03fec11](https://github.com/whisper-money/whisper-money/commit/03fec11705acc7521d4b179d41ae59af60f34023))
* Decrypt encrypted transactions on key unlock ([#123](https://github.com/whisper-money/whisper-money/issues/123)) ([6abec95](https://github.com/whisper-money/whisper-money/commit/6abec95d0eee266e1a4570400bd82d2e5228695e))
* Docker dev env with Caddy, PHP on host ([#103](https://github.com/whisper-money/whisper-money/issues/103)) ([caccac6](https://github.com/whisper-money/whisper-money/commit/caccac6166c2e4fc9d485c710f06a65c1dd7360e))
* Enable email verification on sign up ([#97](https://github.com/whisper-money/whisper-money/issues/97)) ([370d388](https://github.com/whisper-money/whisper-money/commit/370d388d99e01ab07fcfdec0991701e5204a30c3))
* Improve PWA standalone experience and redirect to dashboard ([#90](https://github.com/whisper-money/whisper-money/issues/90)) ([b4897ef](https://github.com/whisper-money/whisper-money/commit/b4897ef4250fc467d01f57e7dc08ecf21faeb183)), closes [#71](https://github.com/whisper-money/whisper-money/issues/71)
* Integrate EnableBanking as open banking provider ([#106](https://github.com/whisper-money/whisper-money/issues/106)) ([db7b6e4](https://github.com/whisper-money/whisper-money/commit/db7b6e4da7d6513c9fb088f4460256550cce246f))
* Plaintext transactions behind feature flag ([#105](https://github.com/whisper-money/whisper-money/issues/105)) ([e35f712](https://github.com/whisper-money/whisper-money/commit/e35f7125b31e7682f36081cfb5f5750cf0433631))
* Redirect to dashboard when running as installed PWA ([#92](https://github.com/whisper-money/whisper-money/issues/92)) ([1d1c0c3](https://github.com/whisper-money/whisper-money/commit/1d1c0c36fe3041dc8bc179dc2b96aaba4fd87214))
* Remove dev command from whispermoney ([1930cf2](https://github.com/whisper-money/whisper-money/commit/1930cf229eb96508d158e145f47a7c7c82821f49))
* Replace settings sidebar with dropdown on mobile ([#117](https://github.com/whisper-money/whisper-money/issues/117)) ([b69138d](https://github.com/whisper-money/whisper-money/commit/b69138df60ecc7358cbbdb698c0f3d4767b1c643))
* Replace user avatar with Facehash faces ([#86](https://github.com/whisper-money/whisper-money/issues/86)) ([6aa9da3](https://github.com/whisper-money/whisper-money/commit/6aa9da3df39e768a87037d5d4bb9d6f981728714))
* Show loading spinner on landing page when in PWA mode ([#96](https://github.com/whisper-money/whisper-money/issues/96)) ([21d36bb](https://github.com/whisper-money/whisper-money/commit/21d36bb53b849e17a9c3e46b065d64607e48188f))
* Show PWA install button on mobile landing page ([#99](https://github.com/whisper-money/whisper-money/issues/99)) ([abc71da](https://github.com/whisper-money/whisper-money/commit/abc71daa7e65b386eb30ba9672c8288016665a56))
* Spanish localization ([#74](https://github.com/whisper-money/whisper-money/issues/74)) ([70b603e](https://github.com/whisper-money/whisper-money/commit/70b603e901c058c40f19b7c7ce1d31c7ecb0f640))

## [0.1.9](https://github.com/whisper-money/whisper-money/compare/v0.1.8...v0.1.9) (2026-01-28)


### Bug Fixes

* Apply automation rule labels on transaction creation and import ([#79](https://github.com/whisper-money/whisper-money/issues/79)) ([a6a2a0d](https://github.com/whisper-money/whisper-money/commit/a6a2a0d58cd1f2dee3dd524567e7ccf6a074c02a)), closes [#61](https://github.com/whisper-money/whisper-money/issues/61)
* Delete transactions on local browser DB after deleting it on the backend ([d1f69a2](https://github.com/whisper-money/whisper-money/commit/d1f69a284a28386b124bbbea295ce8064ab2a362))


### Features

* Print sponsor message on whispermoney script ([f03fcf5](https://github.com/whisper-money/whisper-money/commit/f03fcf5ac61517b20b82bffd3277a6ab66098d89))
* Release budgets feature to all users ([#84](https://github.com/whisper-money/whisper-money/issues/84)) ([a9b889b](https://github.com/whisper-money/whisper-money/commit/a9b889b1459ba9dd5f857ce30c837a183f77dc79))
* Reload transactions table on import proccess complete ([bbc3027](https://github.com/whisper-money/whisper-money/commit/bbc302754541f4c61080284f8ca729fe5aea4ecf))
* Sync new users to Resend contacts ([#85](https://github.com/whisper-money/whisper-money/issues/85)) ([952a5d4](https://github.com/whisper-money/whisper-money/commit/952a5d4be784634ba1b2095621fabed3fd86d56d))

## [0.1.8](https://github.com/whisper-money/whisper-money/compare/v0.1.7...v0.1.8) (2026-01-25)

### Bug Fixes

- Fire transaction updated event after a label change ([#73](https://github.com/whisper-money/whisper-money/issues/73)) ([134a292](https://github.com/whisper-money/whisper-money/commit/134a292ddb5d58b7428c4a50becee8dd957e4c09))
- Progress bar color on dark scheme ([d216d0c](https://github.com/whisper-money/whisper-money/commit/d216d0c071e8ff380627122f8f70e947bb7f667b))
- Typo in composer dev command ([f30e600](https://github.com/whisper-money/whisper-money/commit/f30e600b75fb71f28e63ee88ec1bc414038adba5))
- Update transactions ([91dd23e](https://github.com/whisper-money/whisper-money/commit/91dd23edc05e3efa72723347ac5b010ebea5c479))

### Features

- Add label support to single transaction update endpoint ([#75](https://github.com/whisper-money/whisper-money/issues/75)) ([e5eca1e](https://github.com/whisper-money/whisper-money/commit/e5eca1eacb86aec87f6aee8a9a685400778d2583))
- Load transactions history on budget created ([#72](https://github.com/whisper-money/whisper-money/issues/72)) ([fee7ad3](https://github.com/whisper-money/whisper-money/commit/fee7ad36abd899d63b220a9d7ad0b670d9feec7f))

## [0.1.7](https://github.com/whisper-money/whisper-money/compare/v0.1.6...v0.1.7) (2026-01-21)

### Bug Fixes

- Error showing randomg transactions from local browser DB ([a7c8544](https://github.com/whisper-money/whisper-money/commit/a7c8544249a887bb96f256d9d336a9b8e13090f1))
- unused vars ([f1a2d78](https://github.com/whisper-money/whisper-money/commit/f1a2d787e5be6c096703f27a5463696f6095f72c))

### Features

- Add PostHog ([#70](https://github.com/whisper-money/whisper-money/issues/70)) ([f5d09eb](https://github.com/whisper-money/whisper-money/commit/f5d09eb2475dc3c1c76dd6a7c23fadb771576fdb))

## [0.1.6](https://github.com/whisper-money/whisper-money/compare/v0.1.5...v0.1.6) (2026-01-19)

### Bug Fixes

- MYSQL_EXTRA_OPTIONS env var ([49ed94c](https://github.com/whisper-money/whisper-money/commit/49ed94cbc7f0d55bf3fef38ce1a620449fe51e1e))

### Features

- Better, easier, and faster account balance update modal ([#65](https://github.com/whisper-money/whisper-money/issues/65)) ([f4ab918](https://github.com/whisper-money/whisper-money/commit/f4ab9181e1235885f0f8158d67de2cd719bfb0d3))
- Don't check upgrades if not in main branch or in DEV_MODE ([16a331a](https://github.com/whisper-money/whisper-money/commit/16a331ab5f654e332bfd0625f3621b99c13f61dd))

## [0.1.5](https://github.com/whisper-money/whisper-money/compare/v0.1.3...v0.1.5) (2026-01-17)

### Bug Fixes

- broken dashboard while loading ([253fe44](https://github.com/whisper-money/whisper-money/commit/253fe447bdd999db78f7fa96e2ffa34e8194e5ce))
- Check IDOR vulnerabilities ([#60](https://github.com/whisper-money/whisper-money/issues/60)) ([80117c3](https://github.com/whisper-money/whisper-money/commit/80117c3edeaf5c5a5166f3815fc555a15b5ce686))
- delay emails to avoid reaching daily resend limit ([8ac2520](https://github.com/whisper-money/whisper-money/commit/8ac25200dc9ed5a5b4e24e36e32668e52ea95477))
- Remove scheduled horizon command (unused anymore) ([63bde93](https://github.com/whisper-money/whisper-money/commit/63bde938b51d5f13a6f817a0beb5d91f48f3d6f3))
- Use user currency in top spending categories card ([#57](https://github.com/whisper-money/whisper-money/issues/57)) ([21a4d87](https://github.com/whisper-money/whisper-money/commit/21a4d87f8562a0e95a62abe261cff7accb8fb2b2)), closes [#56](https://github.com/whisper-money/whisper-money/issues/56)

### Features

- Add wispermoney local command ([#59](https://github.com/whisper-money/whisper-money/issues/59)) ([ffd9694](https://github.com/whisper-money/whisper-money/commit/ffd96949e5e682aa42904d241772ba87ac72a067))
- Auto-open encryption key modal after login ([#54](https://github.com/whisper-money/whisper-money/issues/54)) ([d16282d](https://github.com/whisper-money/whisper-money/commit/d16282dbad7c7b58843c28dedf0a04265355a8a6))
- Automated setup script for local deployment ([#58](https://github.com/whisper-money/whisper-money/issues/58)) ([819bea1](https://github.com/whisper-money/whisper-money/commit/819bea19223bdf2a33ff4a66c2e4803f26fbaf5e))
- Group small expending categories on the Sankey chart ([5618893](https://github.com/whisper-money/whisper-money/commit/5618893be8a0e0255e1abd7b3e2ff7c65e3eb046))
- Persist transactions filter on the URL ([c9877a5](https://github.com/whisper-money/whisper-money/commit/c9877a503dea45505dc46a1b9b23142e0aefc290))
- Persist cashflow period on the URL ([1343e1c](https://github.com/whisper-money/whisper-money/commit/1343e1c75fc645ae7253f2d02b50178243cb70d9))

## [0.1.4](https://github.com/whisper-money/whisper-money/compare/v0.1.3...v0.1.4) (2026-01-11)

### Bug Fixes

- delay emails to avoid reaching daily resend limit ([8ac2520](https://github.com/whisper-money/whisper-money/commit/8ac25200dc9ed5a5b4e24e36e32668e52ea95477))
- Remove scheduled horizon command (unused anymore) ([63bde93](https://github.com/whisper-money/whisper-money/commit/63bde938b51d5f13a6f817a0beb5d91f48f3d6f3))

### Features

- Group small expending categories on the Sankey chart ([5618893](https://github.com/whisper-money/whisper-money/commit/5618893be8a0e0255e1abd7b3e2ff7c65e3eb046))
- Persist transactions filter on the URL ([c9877a5](https://github.com/whisper-money/whisper-money/commit/c9877a503dea45505dc46a1b9b23142e0aefc290))
- Persist cashflow period on the URL ([1343e1c](https://github.com/whisper-money/whisper-money/commit/1343e1c75fc645ae7253f2d02b50178243cb70d9))

## [0.1.3](https://github.com/whisper-money/whisper-money/compare/v0.1.1...v0.1.3) (2026-01-09)

### Bug Fixes

- issue on filters when no label created ([cb1d6a2](https://github.com/whisper-money/whisper-money/commit/cb1d6a230f0c1c0734e5267a5a4d2753f4b91cff))
- scroll category combobox to top while searching ([c1ddc14](https://github.com/whisper-money/whisper-money/commit/c1ddc1477d89c9bfc9aa36bc81f8d48fda05208a))

### Features

- new roadmap and feedback links ([0646b38](https://github.com/whisper-money/whisper-money/commit/0646b380cecc6c3a2859206429a84c0e3ac1c798))
- Send custom emails to users ([#52](https://github.com/whisper-money/whisper-money/issues/52)) ([683b3f3](https://github.com/whisper-money/whisper-money/commit/683b3f32a7a1467a9fd2e269903570c33164ff83))

## [0.1.2](https://github.com/whisper-money/whisper-money/compare/v0.1.1...v0.1.2) (2026-01-07)

### New Features

- Cashflow view ([475c650](https://github.com/whisper-money/whisper-money/pull/49))
- Demo account ([8addcad](https://github.com/whisper-money/whisper-money/pull/51))

### Bug Fixes

- issue on filters when no label created ([cb1d6a2](https://github.com/whisper-money/whisper-money/commit/cb1d6a230f0c1c0734e5267a5a4d2753f4b91cff))

## 0.1.1 (2026-01-05)

### Bug Fixes

- add SSR guards to localStorage/sessionStorage access ([3b56e24](https://github.com/whisper-money/whisper-money/commit/3b56e2444713f922bccc2790f676dab167758500))
- add SyncProvider to SSR entry point ([3177fa3](https://github.com/whisper-money/whisper-money/commit/3177fa3519e2728c813f7532bfc7c65b603398b7))
- app logo icon auto of the dashboard ([e813849](https://github.com/whisper-money/whisper-money/commit/e813849e7ba352f1ad0100fd77a41d770bed1968))
- apply border radius to visible bar segments in stacked chart ([413f83f](https://github.com/whisper-money/whisper-money/commit/413f83f96163b1ae6ce5e62d810fbdaccae480d6))
- asd key element to accounts index page ([8eab41a](https://github.com/whisper-money/whisper-money/commit/8eab41ac89747437f3afcd27e90012ddc8d1e3dd))
- auto-regenerate APP_KEY if invalid format (missing base64: prefix) ([797cb06](https://github.com/whisper-money/whisper-money/commit/797cb06f86037a1f89b0875aa5ed38307c70ed57))
- automated rules broken and now they work in batches ([890593d](https://github.com/whisper-money/whisper-money/commit/890593d9674d0aacf9f4491a49e36ca6884afa9b))
- Automated rules with labels ([#32](https://github.com/whisper-money/whisper-money/issues/32)) ([bf0c9ae](https://github.com/whisper-money/whisper-money/commit/bf0c9ae989f2543b7093630bfa0723c669689b3b))
- bulk action bar style ([045c7a5](https://github.com/whisper-money/whisper-money/commit/045c7a5752081eb0b1ba9cbe5744eab13ad2d7c5))
- **category-combobox:** Improve UI responsiveness and truncate category names ([2cecd01](https://github.com/whisper-money/whisper-money/commit/2cecd014e0cff0aefe70ace625baacbf58255f6d))
- **charts:** mobile ui, and desktop tooltips ([818a49e](https://github.com/whisper-money/whisper-money/commit/818a49e79956f16d71e01736593cec762bb67a46))
- deploy ci ([d4410a6](https://github.com/whisper-money/whisper-money/commit/d4410a67fe81e0e409138ab7913b7e3787604e66))
- increase nginx buffer sizes ([a87b36d](https://github.com/whisper-money/whisper-money/commit/a87b36de3f4416abacb232976ca3d113592d32fa))
- make encryption key storage SSR-safe to prevent 502 errors ([0fcc66e](https://github.com/whisper-money/whisper-money/commit/0fcc66e25d2eba710111e0da2bed64bbe5ee9110))
- make useIsMobile hook and utility functions SSR-safe ([40762bc](https://github.com/whisper-money/whisper-money/commit/40762bc528447f42b39ca5121a9047f376ffbe6b))
- migration history ([b52e2de](https://github.com/whisper-money/whisper-money/commit/b52e2de9870294e0aa5a8da5f047a51930d52167))
- **mobile:** account chart ([14a9343](https://github.com/whisper-money/whisper-money/commit/14a9343c1d5142beea7bc9dbfa130510fd5addbc))
- normalize transaction_date to YYYY-MM-DD for duplicate detection ([#4](https://github.com/whisper-money/whisper-money/issues/4)) ([7492b2e](https://github.com/whisper-money/whisper-money/commit/7492b2e7360f6b8e53be891ce55a74e0b4fa6c66))
- re-enable ssr for all routes after issue is fixed ([1d96f5d](https://github.com/whisper-money/whisper-money/commit/1d96f5dc63b6a8abf6107f683ceb9c73fc8763b1))
- rong schedule import ([c684695](https://github.com/whisper-money/whisper-money/commit/c684695008cbf180cc4a621b9fc325ee8669e5da))
- **sync:** make transaction creation idempotent ([#38](https://github.com/whisper-money/whisper-money/issues/38)) ([3cbe0a7](https://github.com/whisper-money/whisper-money/commit/3cbe0a7879df68affe62944901dfc2054855fbf1))
- toast on mobile ([716e21b](https://github.com/whisper-money/whisper-money/commit/716e21b219a31a07b8e6cf859567b45e15d1a485))
- transaction list on account page ([ce09f32](https://github.com/whisper-money/whisper-money/commit/ce09f32a9290561363169ec7a7d3b85999aaf35e))
- **TransactionFilters:** Update badge styling for uncategorized selection ([a2d7af2](https://github.com/whisper-money/whisper-money/commit/a2d7af27898040dcfbb7287ba8803edbf28db14d))
- **transactions:** Decrypt account names for automation rule evaluation ([323b738](https://github.com/whisper-money/whisper-money/commit/323b7386c1e5e1cfbf32258d7430b2e3686e4b4c))
- **transactions:** We were creating transactions with numberic ID instead of UUID v7 ([52e1a7b](https://github.com/whisper-money/whisper-money/commit/52e1a7bd955d0018ba5a2cfa761e6c58aaa81d3f))
- use direct PDO connection test for MySQL readiness check ([a7ee776](https://github.com/whisper-money/whisper-money/commit/a7ee776af791a92f42fada35965476b9d903b50a))
- use markdown to send user lead invitation mail ([1e9566a](https://github.com/whisper-money/whisper-money/commit/1e9566a289125133d23bba7a7ed2102e126b5a08))
- wrap SSR app with EncryptionKeyProvider ([770f091](https://github.com/whisper-money/whisper-money/commit/770f091b9b4509e0b5ca51ded1080b228594500e))
- wrong user menu text ([b2d1bcf](https://github.com/whisper-money/whisper-money/commit/b2d1bcf54c7061ab6cc2adb8182795eedd20233d))

### Features

- **.cursor:** Add whisper-money rule configuration ([e80647d](https://github.com/whisper-money/whisper-money/commit/e80647dc130f1c4b5f51857b27649229cf887701))
- **AccountBalanceSync:** Update existing balances and add new ones efficiently ([c2c6894](https://github.com/whisper-money/whisper-money/commit/c2c6894cb860e768fdb2c5ece746bf97129784db))
- Add account balance chart improvements and icons ([#5](https://github.com/whisper-money/whisper-money/issues/5)) ([5f149b4](https://github.com/whisper-money/whisper-money/commit/5f149b4bae7065f2c2aaa191941bdc3fa9dfe41e))
- Add bank selection to edit transaction dialog ([0473371](https://github.com/whisper-money/whisper-money/commit/0473371fce68f95cbce5aa3bf590253e56c7129d))
- Add Discord invite link to welcome page ([f3c0fa1](https://github.com/whisper-money/whisper-money/commit/f3c0fa1355921a2dceab1e1dd5df5e0cd5527c7f))
- Add financial models and seeders ([635cde0](https://github.com/whisper-money/whisper-money/commit/635cde021b59c9078e72882327c17d500503d22a))
- Add import transactions button to transactions page ([e5a77a9](https://github.com/whisper-money/whisper-money/commit/e5a77a9aca92cc8b12e09d24402ef3d84a223b0e))
- add multiple chart view modes for net worth evolution ([#37](https://github.com/whisper-money/whisper-money/issues/37)) ([c5df59c](https://github.com/whisper-money/whisper-money/commit/c5df59c285b253ac5f4bbef36a4523fe885491af))
- Add new category icons and colors ([c339105](https://github.com/whisper-money/whisper-money/commit/c33910587585ea8da4dfde4b79aa14498fc58692))
- Add privacy mode to hide monetary amounts ([#28](https://github.com/whisper-money/whisper-money/issues/28)) ([8811afb](https://github.com/whisper-money/whisper-money/commit/8811afbad8f5ef2dae0ebb8562a66d8ae9aa3938))
- add transaction labels feature ([#24](https://github.com/whisper-money/whisper-money/issues/24)) ([4b5d65b](https://github.com/whisper-money/whisper-money/commit/4b5d65ba03371c7b85bab0b64ec4dc8d19b015b3))
- add version tracking with git tags and changelog ([db81c9b](https://github.com/whisper-money/whisper-money/commit/db81c9b88861dd60eef97eba035cf03ca1a7d6a1))
- **auth:** Add key clearing on login ([3795e46](https://github.com/whisper-money/whisper-money/commit/3795e46d4fb11e228524f2e8557cd931a315db8e))
- **automation:** Add re-evaluate all transactions functionality ([e937a86](https://github.com/whisper-money/whisper-money/commit/e937a8647dbe69fbd93ea2b5ddad44bbe7ba4a18))
- **automation:** Add sync functionality to automation rule dialogs ([e009abb](https://github.com/whisper-money/whisper-money/commit/e009abbee19252bab2dbcc18170c54870df9f5b9))
- **category:** Update default categories list and sorting logic ([73d847f](https://github.com/whisper-money/whisper-money/commit/73d847f38b35e3c25a3f890574e42b5210d12d67))
- centralize pricing config with multiple plans support ([#20](https://github.com/whisper-money/whisper-money/issues/20)) ([58b9343](https://github.com/whisper-money/whisper-money/commit/58b934333f55a43372fefd634cde05a3b0109859))
- Configure Resend email integration ([#34](https://github.com/whisper-money/whisper-money/issues/34)) ([3c22453](https://github.com/whisper-money/whisper-money/commit/3c22453fc611a109d69ed3c6bff2e6fb12163aba))
- **Docker:** Add Bun installation and update build process ([4379239](https://github.com/whisper-money/whisper-money/commit/43792392b4e9b3213b39348eeaa002e13348df9a))
- **Docker:** Add Wayfinder route generation and update asset build process ([a13e7fd](https://github.com/whisper-money/whisper-money/commit/a13e7fd538628b0ebc1c1b0a9893a5b36b2b32d2))
- **Docker:** Optimize build process by removing unnecessary steps and adjusting environment variables ([732775e](https://github.com/whisper-money/whisper-money/commit/732775e47ef92f01f0449b2cad1e337627bd5a4b))
- **Docker:** Replace pnpm with Bun for Node.js package management ([5b45006](https://github.com/whisper-money/whisper-money/commit/5b450067eb51e003e0074a44276587d7afe8514c))
- **Docker:** Replace pnpm with bun for package management and build process ([b4b891f](https://github.com/whisper-money/whisper-money/commit/b4b891f204a7bf8fe1f1b9c036cfee6052a18bd4))
- **encrypted-text:** Add animation and random character generation ([7d8474f](https://github.com/whisper-money/whisper-money/commit/7d8474f6b81f032ac4585fceb293c9d5e6e5594d))
- **encrypted-text:** Improve encryption UI with dynamic masking and loading state ([ff186a4](https://github.com/whisper-money/whisper-money/commit/ff186a4887c715b10205508d41f453df90201b26))
- Implement drip email campaign system ([#35](https://github.com/whisper-money/whisper-money/issues/35)) ([46c5b13](https://github.com/whisper-money/whisper-money/commit/46c5b137392a333c98ebcb6d3435556b52a18994))
- **import-transactions-drawer:** Add json-logic-js dependency and improve import logic ([1df3bad](https://github.com/whisper-money/whisper-money/commit/1df3bad3c3d27e4fe224277c4aedb8872fb6ba25))
- **lucide-react:** Add custom icons to Toaster component ([573b2fd](https://github.com/whisper-money/whisper-money/commit/573b2fdb0a13cd2c2064996c8660a98ed97a60c2))
- **queue:** Implement queueable email jobs with rate limiting ([3d0d6c8](https://github.com/whisper-money/whisper-money/commit/3d0d6c8bef11e06e3a39b7a8e9dbc4fb166657e7))
- **react:** add authentication check in SyncProvider ([48bce81](https://github.com/whisper-money/whisper-money/commit/48bce81d9a23f894008bdfaa9c6876431f0c293e))
- Remove console.log and add padding to components ([c1f99fe](https://github.com/whisper-money/whisper-money/commit/c1f99fedd6255621e3c9a301d79bbe3968908aea))
- Replace Input with Textarea for editable descriptions ([2b6acf4](https://github.com/whisper-money/whisper-money/commit/2b6acf49d8770c74538e0f8664d9e88b4ae0b63e))
- **settings:** Update account management UI and add sync functionality ([ab63edd](https://github.com/whisper-money/whisper-money/commit/ab63edde2b23f1a9055fcce7b456a4825251cebb))
- **shared:** Add CategoryCombobox component ([57879bb](https://github.com/whisper-money/whisper-money/commit/57879bb7118850ae03ed2059dc5b775c29f5885d))
- **sync:** Add sync functionality for accounts, banks, categories, and status button ([9256148](https://github.com/whisper-money/whisper-money/commit/9256148961201ba52fe93d29517fb6c0dbf24147))
- **traefik:** Add secure headers middleware to WhisperMoney service ([242be5f](https://github.com/whisper-money/whisper-money/commit/242be5f415be11696fafdf4db68f4dafae964c66))
- **TransactionController:** Add store method for creating transactions ([c1fbd4d](https://github.com/whisper-money/whisper-money/commit/c1fbd4d09fe67a092ad45e49b97ce7a172cf9913))
- **TransactionSyncController:** Sort transactions by transaction_date and updated_at ([41f5c64](https://github.com/whisper-money/whisper-money/commit/41f5c6485c11934e69c6efab2868ea541e2856d4))
- **ui:** Implement virtual scrolling for DataTable component ([07ca633](https://github.com/whisper-money/whisper-money/commit/07ca63347e9bae5bc59b8f0f8073e64da1df68f4))
- **ui:** Improve chart tooltip content rendering and calculation ([d04b6a0](https://github.com/whisper-money/whisper-money/commit/d04b6a0174910f5e8eb4dce491805e60d7e67c04))
- update date formatting logic in transaction components ([d13ecc2](https://github.com/whisper-money/whisper-money/commit/d13ecc2722509501d018b27a3b4dd83e7ab4351b))
- Update encryption key button icon based on state ([08baf3b](https://github.com/whisper-money/whisper-money/commit/08baf3b19a8d4a631d2942a31e47071be68a128c))
- Update ProfileController to include two-factor authentication settings ([e21c9cc](https://github.com/whisper-money/whisper-money/commit/e21c9cc3a89fdb8ac84bea49e4a1f6963ab7542e))
- Update welcome page title to focus on understanding finances ([3ac7102](https://github.com/whisper-money/whisper-money/commit/3ac71025013ed1c8da713c753b9ef2bd3e050eee))
- **use-dashboard-data:** Add conditional formatting for current year dates ([525e770](https://github.com/whisper-money/whisper-money/commit/525e7709cc8c92f90ece1bfce572e8434de60b15))
- **welcome:** Add GitHub link and refactor auth buttons ([2ab362d](https://github.com/whisper-money/whisper-money/commit/2ab362dc5db7fa14104232cce283e53f5b658761))

### Reverts

- Revert "swap horizon -> queue:work on mysql" ([03880ca](https://github.com/whisper-money/whisper-money/commit/03880ca4920eba081d33147ceedd982f81c1a65b))

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
