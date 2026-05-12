# Changelog

## [0.2.1](https://github.com/whisper-money/whisper-money/compare/v0.2.0...v0.2.1) (2026-05-12)


### Features

* Add yearly budget period ([#384](https://github.com/whisper-money/whisper-money/issues/384)) ([f8f3b06](https://github.com/whisper-money/whisper-money/commit/f8f3b06))
* Add labels to automation rules ([#379](https://github.com/whisper-money/whisper-money/issues/379)) ([5b8e7e8](https://github.com/whisper-money/whisper-money/commit/5b8e7e8))


### Bug Fixes

* Fix exchange rate cache race (PHP-LARAVEL-1V) ([#383](https://github.com/whisper-money/whisper-money/issues/383)) ([c3dcbb4](https://github.com/whisper-money/whisper-money/commit/c3dcbb4))
* Fix cashflow null category rows ([#382](https://github.com/whisper-money/whisper-money/issues/382)) ([30cc4da](https://github.com/whisper-money/whisper-money/commit/30cc4da))
* Fix browser translation crash (PHP-LARAVEL-1S) ([#381](https://github.com/whisper-money/whisper-money/issues/381)) ([e635fda](https://github.com/whisper-money/whisper-money/commit/e635fda))
* Fix cashflow multi-currency totals ([#380](https://github.com/whisper-money/whisper-money/issues/380)) ([4e03996](https://github.com/whisper-money/whisper-money/commit/4e03996))
* Fix service worker registration rejection ([#376](https://github.com/whisper-money/whisper-money/issues/376)) ([3526e5f](https://github.com/whisper-money/whisper-money/commit/3526e5f))
* Recover from stale Vite chunks ([#374](https://github.com/whisper-money/whisper-money/issues/374)) ([69610c5](https://github.com/whisper-money/whisper-money/commit/69610c5))
* **sentry:** ignore postMessage clone noise ([#373](https://github.com/whisper-money/whisper-money/issues/373)) ([6335287](https://github.com/whisper-money/whisper-money/commit/6335287))
* Fix Sentry transaction and dashboard crashes ([#372](https://github.com/whisper-money/whisper-money/issues/372)) ([718cfa9](https://github.com/whisper-money/whisper-money/commit/718cfa9))
* Fix Sentry release commit detection in image build ([#371](https://github.com/whisper-money/whisper-money/issues/371)) ([f4ab4a1](https://github.com/whisper-money/whisper-money/commit/f4ab4a1))
* Prevent cached cashflow analytics responses ([#368](https://github.com/whisper-money/whisper-money/issues/368)) ([97df059](https://github.com/whisper-money/whisper-money/commit/97df059))
* Fix duplicate category name validation ([#364](https://github.com/whisper-money/whisper-money/issues/364)) ([e3c2d2f](https://github.com/whisper-money/whisper-money/commit/e3c2d2f))


### Chores

* Add sentry issue slash command ([#375](https://github.com/whisper-money/whisper-money/issues/375)) ([c929c1f](https://github.com/whisper-money/whisper-money/commit/c929c1f))
* Update worktree script ([#366](https://github.com/whisper-money/whisper-money/issues/366)) ([360a38a](https://github.com/whisper-money/whisper-money/commit/360a38a))
* Speed up PR CI browser path ([#365](https://github.com/whisper-money/whisper-money/issues/365)) ([e36d6f3](https://github.com/whisper-money/whisper-money/commit/e36d6f3))

# [0.2.0](https://github.com/whisper-money/whisper-money/compare/v0.1.20...v0.2.0) (2026-05-07)


### Bug Fixes

* **banking:** clamp linkedDateFrom to today on EnableBanking sync ([#343](https://github.com/whisper-money/whisper-money/issues/343)) ([f6c2057](https://github.com/whisper-money/whisper-money/commit/f6c20576b5dd6a98cb69c860825459fe010e2164))
* **budgets:** remove Custom period type to fix duplicate-key crash ([#355](https://github.com/whisper-money/whisper-money/issues/355)) ([22043ce](https://github.com/whisper-money/whisper-money/commit/22043ced29e80486bcc3bb025952fda0f0b1f537))
* **dashboard:** avoid month overflow in real estate projection ([#340](https://github.com/whisper-money/whisper-money/issues/340)) ([8f42496](https://github.com/whisper-money/whisper-money/commit/8f42496a5f6cd655828df7c49f358ad61d7e8002))
* include production Dockerfile in deploy filter ([#350](https://github.com/whisper-money/whisper-money/issues/350)) ([21b5692](https://github.com/whisper-money/whisper-money/commit/21b5692174f2cf23d44a93e26f7b39d21edfe383))
* **onboarding:** guard window access in SSR ([#351](https://github.com/whisper-money/whisper-money/issues/351)) ([b1709b7](https://github.com/whisper-money/whisper-money/commit/b1709b714e5e5d591351db51f7d2b31fb201fe74))
* **real-estate:** compound annual revaluation monthly ([#337](https://github.com/whisper-money/whisper-money/issues/337)) ([13f741a](https://github.com/whisper-money/whisper-money/commit/13f741aaed38681571c5950da844f44309306858))
* unblock onboarding after sync failure ([#346](https://github.com/whisper-money/whisper-money/issues/346)) ([70f3897](https://github.com/whisper-money/whisper-money/commit/70f3897b5534940c4be1dfdce3b4ce8978a882b9))


### Features

* **accounts:** show projection on real estate chart ([#338](https://github.com/whisper-money/whisper-money/issues/338)) ([0f2300b](https://github.com/whisper-money/whisper-money/commit/0f2300bf3e420576893758117ed5583b39f656d7))
* **banking:** back off scheduler when EnableBanking returns 429 ([#352](https://github.com/whisper-money/whisper-money/issues/352)) ([f800847](https://github.com/whisper-money/whisper-money/commit/f80084759133a5e00fc997602266575d3806dfaa))
* **leads:** cohort-based launch invitations with per-user Stripe coupons ([#333](https://github.com/whisper-money/whisper-money/issues/333)) ([ab3d6e9](https://github.com/whisper-money/whisper-money/commit/ab3d6e9fcaeccf3b57027c26904460e788c8df3e))


### Performance Improvements

* **resend:** default sync-leads to last 24h window ([#354](https://github.com/whisper-money/whisper-money/issues/354)) ([e387c03](https://github.com/whisper-money/whisper-money/commit/e387c038ca6e5e0ea3f757e28c52125ea20ba198))

## [0.1.20](https://github.com/whisper-money/whisper-money/compare/v0.1.19...v0.1.20) (2026-04-24)


### Bug Fixes

* **accounts:** use chart color scheme for real estate sparkline and balance charts ([#247](https://github.com/whisper-money/whisper-money/issues/247)) ([8b71115](https://github.com/whisper-money/whisper-money/commit/8b71115afc0f46ec1867e7030bffc87cad481a10))
* add missing port to frontend Bugsink DSN ([#260](https://github.com/whisper-money/whisper-money/issues/260)) ([6ce5b12](https://github.com/whisper-money/whisper-money/commit/6ce5b123ce9b58ae7ec660d8cbcd005fb1748e35))
* align onboarding account types with current asset support ([#273](https://github.com/whisper-money/whisper-money/issues/273)) ([80274e0](https://github.com/whisper-money/whisper-money/commit/80274e03a8e697509ddbd0ec3e7a4e9d5d752d10))
* **auth:** allow forced registration ([#307](https://github.com/whisper-money/whisper-money/issues/307)) ([75736f3](https://github.com/whisper-money/whisper-money/commit/75736f3e59966e6821f436d4aac7f45e4111e5da))
* avoid iOS PWA status bar overlap ([#281](https://github.com/whisper-money/whisper-money/issues/281)) ([80b6668](https://github.com/whisper-money/whisper-money/commit/80b666836c9ad106c526eb45c82046af953c0342))
* **banking:** retry failed sync connections and log every sync attempt ([#251](https://github.com/whisper-money/whisper-money/issues/251)) ([f3b5929](https://github.com/whisper-money/whisper-money/commit/f3b5929ecc2ca4d093e645ff996fc47b63440e17))
* batch Pennant feature flag queries to avoid N+1 selects ([#244](https://github.com/whisper-money/whisper-money/issues/244)) ([8ac6ed4](https://github.com/whisper-money/whisper-money/commit/8ac6ed4d83e14eaab9fe8215247e091fab8258c3)), closes [#241](https://github.com/whisper-money/whisper-money/issues/241)
* **budgets:** make budget assignment idempotent ([#303](https://github.com/whisper-money/whisper-money/issues/303)) ([b1ceda6](https://github.com/whisper-money/whisper-money/commit/b1ceda61f93d1bb385060b7ffee35fb56fd41962))
* **budgets:** retry assignment deadlocks ([#304](https://github.com/whisper-money/whisper-money/issues/304)) ([45e311e](https://github.com/whisper-money/whisper-money/commit/45e311e17baaa510a4309724937c5b18ded42631))
* **cashflow:** exclude transfer categories from sankey ([#235](https://github.com/whisper-money/whisper-money/issues/235)) ([debb47f](https://github.com/whisper-money/whisper-money/commit/debb47f6af2808669a319a696d9a81036ca7b961))
* **cashflow:** net transfer categories in sankey ([#257](https://github.com/whisper-money/whisper-money/issues/257)) ([83f7e83](https://github.com/whisper-money/whisper-money/commit/83f7e83a134db2fe98f4b3ba75f173b7e0f44e44))
* **cashflow:** read period from server props instead of window ([#302](https://github.com/whisper-money/whisper-money/issues/302)) ([22952c4](https://github.com/whisper-money/whisper-money/commit/22952c4e75cfbe933b42c91da826ff0e33e472e3))
* **chart:** hide tooltip on scroll with opacity fade ([#320](https://github.com/whisper-money/whisper-money/issues/320)) ([38e1976](https://github.com/whisper-money/whisper-money/commit/38e1976270b3afafac93d02a5586c508762e25af))
* **chart:** tooltip escapes overflow, truncates long labels ([#317](https://github.com/whisper-money/whisper-money/issues/317)) ([e4d2ade](https://github.com/whisper-money/whisper-money/commit/e4d2ade92f4c532fa040a9b98e2fcee2ba5cc3b9))
* **ci:** order sentry deploy after build ([#309](https://github.com/whisper-money/whisper-money/issues/309)) ([bfe1af3](https://github.com/whisper-money/whisper-money/commit/bfe1af3c839e3370d5b6132efdaaad5a6b9983a3))
* **ci:** skip outdated production deploys ([b36197e](https://github.com/whisper-money/whisper-money/commit/b36197e76bca7b73cc50f4f53775974326cae264))
* clarify account creation modal copy ([#274](https://github.com/whisper-money/whisper-money/issues/274)) ([dafc58f](https://github.com/whisper-money/whisper-money/commit/dafc58f49f0a832a45bbd3f02fd39340e575a4d7))
* clarify mobile settings navigation ([#272](https://github.com/whisper-money/whisper-money/issues/272)) ([62ab1b3](https://github.com/whisper-money/whisper-money/commit/62ab1b38db8fc03e4e3172cc31676442b850deaf))
* **dashboard:** dismiss account card tooltip when tapping outside ([#318](https://github.com/whisper-money/whisper-money/issues/318)) ([753002f](https://github.com/whisper-money/whisper-money/commit/753002f930f4abe8c8025bac7f28609d1694152c))
* **dashboard:** treat loans as debt in net worth ([#238](https://github.com/whisper-money/whisper-money/issues/238)) ([f140b5d](https://github.com/whisper-money/whisper-money/commit/f140b5df7f2188dde8d278eca47a4e8eaa431f86))
* default account charts to user currency ([#271](https://github.com/whisper-money/whisper-money/issues/271)) ([38cf672](https://github.com/whisper-money/whisper-money/commit/38cf672c8e9ba24e8f8f956e2b19a2c05c98064a))
* default to standard onboarding option ([#276](https://github.com/whisper-money/whisper-money/issues/276)) ([d91d9d3](https://github.com/whisper-money/whisper-money/commit/d91d9d3b3eb2ac7c6c9deed2ef2454835daf5d5a))
* **demo-reset:** use renamed 'ING Direct' bank ([#301](https://github.com/whisper-money/whisper-money/issues/301)) ([cfa54a2](https://github.com/whisper-money/whisper-money/commit/cfa54a2d9dc9b8031d18528b51bde933ed501729))
* **docker:** ensure www-data owns storage after artisan commands ([#329](https://github.com/whisper-money/whisper-money/issues/329)) ([0eca002](https://github.com/whisper-money/whisper-money/commit/0eca00285699ca67dccd6c7ab8ec5af853a951fc))
* expose pi mcp extension as mcps.ts ([#315](https://github.com/whisper-money/whisper-money/issues/315)) ([c7cfa10](https://github.com/whisper-money/whisper-money/commit/c7cfa1011764be700687da5e499de1fde3445e65))
* **i18n:** add missing Spanish translations for mortgage UI strings ([0a535fb](https://github.com/whisper-money/whisper-money/commit/0a535fbf4729afc4bf0c791faddbfc71397c01ef))
* **i18n:** translate Unknown Income/Expense and other missing ES strings ([#331](https://github.com/whisper-money/whisper-money/issues/331)) ([79075db](https://github.com/whisper-money/whisper-money/commit/79075dbcdf2003373483afd396d2b4cb4b415f6a))
* keep iOS content below the notch ([#280](https://github.com/whisper-money/whisper-money/issues/280)) ([b505d68](https://github.com/whisper-money/whisper-money/commit/b505d68ef0ac4d52ee94a85b4e6b113c9d8d35c9))
* keep iOS popovers below the notch ([#282](https://github.com/whisper-money/whisper-money/issues/282)) ([ea9956f](https://github.com/whisper-money/whisper-money/commit/ea9956f21da3f7498bf947f539c6b31fa844fe96))
* limit bank sync emails to one per day ([#290](https://github.com/whisper-money/whisper-money/issues/290)) ([552aa59](https://github.com/whisper-money/whisper-money/commit/552aa59aaf5e476c81d81483ca9118f872730d2e))
* **loans:** project monthly balances from actual entries instead of original params ([#259](https://github.com/whisper-money/whisper-money/issues/259)) ([7e95828](https://github.com/whisper-money/whisper-money/commit/7e958284e3944a9bf3dfae08524f81afbca4a7da))
* make transaction sync email use default sender ([#265](https://github.com/whisper-money/whisper-money/issues/265)) ([7be0fe0](https://github.com/whisper-money/whisper-money/commit/7be0fe012041283df651c1fbce7b3f69102a500f))
* **open-banking:** respect local email hours ([#306](https://github.com/whisper-money/whisper-money/issues/306)) ([fbffdd3](https://github.com/whisper-money/whisper-money/commit/fbffdd3f3c16ae075bb2d779e22d1b4e82a792e9))
* **open-banking:** skip silent sync emails ([#295](https://github.com/whisper-money/whisper-money/issues/295)) ([473ac03](https://github.com/whisper-money/whisper-money/commit/473ac03088b3ad6e09c32344e0b4ca5f1db489ea))
* **open-banking:** sort bank sync email data ([#292](https://github.com/whisper-money/whisper-money/issues/292)) ([c90e816](https://github.com/whisper-money/whisper-money/commit/c90e8166bfc94f1af7aab2197edd87d68eb9e1b9))
* **open-banking:** suppress first sync email ([#310](https://github.com/whisper-money/whisper-money/issues/310)) ([16675f6](https://github.com/whisper-money/whisper-money/commit/16675f6518ec2e652b711c483d28d4b22792abd6))
* preserve cents in chart amounts ([#270](https://github.com/whisper-money/whisper-money/issues/270)) ([0735ee6](https://github.com/whisper-money/whisper-money/commit/0735ee6d697bd8d46044a223bc1061b8742f035e))
* **pricing:** update final release prices ([#288](https://github.com/whisper-money/whisper-money/issues/288)) ([319ca75](https://github.com/whisper-money/whisper-money/commit/319ca758e1e9869445512e9311b3d26a4197291f))
* prioritize exact bank search matches ([#267](https://github.com/whisper-money/whisper-money/issues/267)) ([1e20361](https://github.com/whisper-money/whisper-money/commit/1e2036110fe05d564069bcc57ffadec4fb8a8147))
* reorder signed names in mail templates ([#266](https://github.com/whisper-money/whisper-money/issues/266)) ([fec9373](https://github.com/whisper-money/whisper-money/commit/fec93734c0dd2d618c00e99247506d314b9b10e7))
* route new PWA guests to signup ([#313](https://github.com/whisper-money/whisper-money/issues/313)) ([905edeb](https://github.com/whisper-money/whisper-money/commit/905edeb4a249cf71a9fccd7815f14fbadc20c884))
* **schedule:** remove stale horizon snapshot ([#293](https://github.com/whisper-money/whisper-money/issues/293)) ([b438a1c](https://github.com/whisper-money/whisper-money/commit/b438a1c73bfb388c784764dbe08b2274c40126ed))
* split drip and default email senders ([#263](https://github.com/whisper-money/whisper-money/issues/263)) ([ce5692c](https://github.com/whisper-money/whisper-money/commit/ce5692cb3036ec47c4f82ae57aaadfd58e6c14a4))
* **user:** persist detected timezones ([#296](https://github.com/whisper-money/whisper-money/issues/296)) ([fde5405](https://github.com/whisper-money/whisper-money/commit/fde5405777250f71cdcc1b45fae73fdb64cd7496))


### Features

* **accounts:** add loan amortization projections for loan accounts ([#246](https://github.com/whisper-money/whisper-money/issues/246)) ([bb65bdc](https://github.com/whisper-money/whisper-money/commit/bb65bdc16e2f0952ec7508dbce418d0155715077))
* **accounts:** add market value and annual revaluation to real estate accounts ([#245](https://github.com/whisper-money/whisper-money/issues/245)) ([fa11dc7](https://github.com/whisper-money/whisper-money/commit/fa11dc78e0c60e310a13708edfd35926f1435a0b))
* **accounts:** add real estate asset tracking ([#241](https://github.com/whisper-money/whisper-money/issues/241)) ([395c4ad](https://github.com/whisper-money/whisper-money/commit/395c4ad2c34b43b341a675ce5526edf2a3d03cd0))
* **accounts:** add today marker on projected balance chart ([#321](https://github.com/whisper-money/whisper-money/issues/321)) ([4b145e2](https://github.com/whisper-money/whisper-money/commit/4b145e230b5a19a25b585260b05f2cc2c19fe066))
* **accounts:** allow setting initial balance when creating balance-tracking accounts ([#239](https://github.com/whisper-money/whisper-money/issues/239)) ([7a05621](https://github.com/whisper-money/whisper-money/commit/7a056213cf6a29eab0b2416f69fd7dfa9ab1061d))
* **accounts:** merge real estate accounts with linked mortgages in UI ([#248](https://github.com/whisper-money/whisper-money/issues/248)) ([6e97635](https://github.com/whisper-money/whisper-money/commit/6e976354ba2e673d5b183bacc3e9a896937ee54f))
* **accounts:** show mortgage data and equity on real estate account page ([#243](https://github.com/whisper-money/whisper-money/issues/243)) ([9732432](https://github.com/whisper-money/whisper-money/commit/973243277a512b40f46d48cae557d240924fe2cb))
* add appearance shortcut to user menu ([#269](https://github.com/whisper-money/whisper-money/issues/269)) ([3acb277](https://github.com/whisper-money/whisper-money/commit/3acb277fb5838c8538b989aa0ba7a8e209ac917f))
* **billing:** apply Stripe tax rates to subscriptions ([#325](https://github.com/whisper-money/whisper-money/issues/325)) ([74cbdd4](https://github.com/whisper-money/whisper-money/commit/74cbdd42efea0e8884639b049a5de7138489fad2))
* **cashflow:** show tracked transfers in Sankey diagram ([#237](https://github.com/whisper-money/whisper-money/issues/237)) ([6dda5f5](https://github.com/whisper-money/whisper-money/commit/6dda5f56ade8d669b9c0843d4980c2d76c9dc614)), closes [hi#level](https://github.com/hi/issues/level)
* **cashflow:** track transfer categories in trends ([#236](https://github.com/whisper-money/whisper-money/issues/236)) ([272dac1](https://github.com/whisper-money/whisper-money/commit/272dac14b82b6863af6eddf88dc54e0fb408c9f1))
* **dashboard:** merge real estate accounts with linked mortgages on dashboard ([752176e](https://github.com/whisper-money/whisper-money/commit/752176e80d67241ab4566d3ced0a7abe8a987b69))
* **landing:** add signed auth links ([#312](https://github.com/whisper-money/whisper-money/issues/312)) ([240fcf1](https://github.com/whisper-money/whisper-money/commit/240fcf17030c605ed5daaa3fffa77018e20968c5))
* link loans to existing properties ([#275](https://github.com/whisper-money/whisper-money/issues/275)) ([a7c1bd3](https://github.com/whisper-money/whisper-money/commit/a7c1bd35ef058f6ef468cdd96a3b9e3a9be89de1))
* **loans:** backfill historical balances on loan creation ([#322](https://github.com/whisper-money/whisper-money/issues/322)) ([5b1d059](https://github.com/whisper-money/whisper-money/commit/5b1d059e020f7aa12c3502b51b174d0615a820e1))
* **open-banking:** remove feature flag gating ([#297](https://github.com/whisper-money/whisper-money/issues/297)) ([244344e](https://github.com/whisper-money/whisper-money/commit/244344e953033b12a948c5f9d85d7db4639bba1d))
* **real-estate:** auto-calculate revaluation % and generate historical balances ([#253](https://github.com/whisper-money/whisper-money/issues/253)) ([094fb1b](https://github.com/whisper-money/whisper-money/commit/094fb1b7446ca57e32e00018b78ddd645eeea3a3))
* resend verification emails to unverified leads ([#287](https://github.com/whisper-money/whisper-money/issues/287)) ([5b78509](https://github.com/whisper-money/whisper-money/commit/5b7850958882988d80080eaa456e599007b974c8))
* selective retry of failed lead email jobs ([#286](https://github.com/whisper-money/whisper-money/issues/286)) ([f408dbe](https://github.com/whisper-money/whisper-money/commit/f408dbe4c8a8ccbd7368ac675525a85b70c9abdf))
* **settings:** centralize currency options and split profile/account support ([#256](https://github.com/whisper-money/whisper-money/issues/256)) ([3d58237](https://github.com/whisper-money/whisper-money/commit/3d5823728a18a146e9c420e7f924014bd66bd3c8))
* store invested_amount in user currency instead of account currency ([#262](https://github.com/whisper-money/whisper-money/issues/262)) ([c3ff4c6](https://github.com/whisper-money/whisper-money/commit/c3ff4c684a50eb1b506d59954442f2ba7a41b04d))
* **stripe:** add promo code generator ([#311](https://github.com/whisper-money/whisper-money/issues/311)) ([69665c3](https://github.com/whisper-money/whisper-money/commit/69665c3c588ad0b4d27594d2b55fdb185553483a))
* **subscriptions:** add configurable trial period to paid plans ([#324](https://github.com/whisper-money/whisper-money/issues/324)) ([b399aaa](https://github.com/whisper-money/whisper-money/commit/b399aaaa0dcafc27f9f9665209f9aceecf0b70e7))
* sync user leads to resend ([#283](https://github.com/whisper-money/whisper-money/issues/283)) ([dc0695c](https://github.com/whisper-money/whisper-money/commit/dc0695c2ca55d3447b814b44bd8f13848922f92a))
* verify waitlist leads ([#285](https://github.com/whisper-money/whisper-money/issues/285)) ([d0aab3d](https://github.com/whisper-money/whisper-money/commit/d0aab3d11bad80fefb35fa01055347ce1413d18b))

## [0.1.19](https://github.com/whisper-money/whisper-money/compare/v0.1.18...v0.1.19) (2026-03-17)


### Bug Fixes

* **banking:** treat 429 rate limit as transient, skip error status on sync ([#224](https://github.com/whisper-money/whisper-money/issues/224)) ([5b9ae2a](https://github.com/whisper-money/whisper-money/commit/5b9ae2a5259ecf1e55e4074295c52dcc0429ef71))
* **cashflow:** only count sign-matching transactions in Sankey category breakdown ([#232](https://github.com/whisper-money/whisper-money/issues/232)) ([9e2a9ca](https://github.com/whisper-money/whisper-money/commit/9e2a9cadfe0210e0f2a45da8dbcaab1552dc0844))
* **ci:** allow deploy retry loop to survive curl timeout ([#233](https://github.com/whisper-money/whisper-money/issues/233)) ([cd40bc7](https://github.com/whisper-money/whisper-money/commit/cd40bc75d9b60acede4fc519f3f8f66ad8f560c3))
* **haptics:** use a local WebHaptics wrapper ([#225](https://github.com/whisper-money/whisper-money/issues/225)) ([f600524](https://github.com/whisper-money/whisper-money/commit/f600524c2b834b9322fda1ca7a6881b43c5d5194))
* prevent account label combobox crash ([#230](https://github.com/whisper-money/whisper-money/issues/230)) ([a60fd6f](https://github.com/whisper-money/whisper-money/commit/a60fd6f452b58d8ba9e4033dffc27a4f0c0fff15))
* **settings:** restore budgets settings redirect ([#228](https://github.com/whisper-money/whisper-money/issues/228)) ([e5fcaee](https://github.com/whisper-money/whisper-money/commit/e5fcaee8f8a0c9badf0450fb209ff7cd7e4c0d2e))


### Features

* **cashflow:** make income/expense category rows clickable to transactions ([#234](https://github.com/whisper-money/whisper-money/issues/234)) ([ec24565](https://github.com/whisper-money/whisper-money/commit/ec245655b8f5541a6bafec92edede97bf75573aa))

## [0.1.18](https://github.com/whisper-money/whisper-money/compare/v0.1.17...v0.1.18) (2026-03-12)


### Bug Fixes

* **banking:** correct backfill-ibans endpoint and handle expired sessions gracefully ([#222](https://github.com/whisper-money/whisper-money/issues/222)) ([08dfb07](https://github.com/whisper-money/whisper-money/commit/08dfb07a90ac4e29b10d5412853d6d11579f3d52))
* **banking:** correct backfill-ibans endpoint, handle expired sessions, and update labels ([#223](https://github.com/whisper-money/whisper-money/issues/223)) ([b92c4ed](https://github.com/whisper-money/whisper-money/commit/b92c4ed149974e1cb1b48af215dbbd6d10f419e4))
* **banking:** update external_account_id on reconnect and store IBAN ([#220](https://github.com/whisper-money/whisper-money/issues/220)) ([4408f71](https://github.com/whisper-money/whisper-money/commit/4408f719b49cb16ea306ab945ce79e507d948ec0))
* **banks:set-logo:** add JPEG support test coverage and prompt for missing arguments ([#214](https://github.com/whisper-money/whisper-money/issues/214)) ([cbe28ff](https://github.com/whisper-money/whisper-money/commit/cbe28ff708a2f94df4f590d913f3f370514be9e9))
* **cashflow:** hide amounts on sankey chart when privacy mode is enabled ([8eb7a0c](https://github.com/whisper-money/whisper-money/commit/8eb7a0cfd79f7b4ed931b696dde5d9ba42039a2e))
* **transactions:** cap description column width to prevent horizontal overflow ([#216](https://github.com/whisper-money/whisper-money/issues/216)) ([28c8df3](https://github.com/whisper-money/whisper-money/commit/28c8df34d5fc8242cc91df3c119caa5832f9a394))


### Features

* **banking:** add banking:backfill-ibans command to populate missing IBANs ([#221](https://github.com/whisper-money/whisper-money/issues/221)) ([07ab9d5](https://github.com/whisper-money/whisper-money/commit/07ab9d5b963de4f7083d86f470923a144b5652ac))
* **connections:** add EnableBanking reconnect flow ([#218](https://github.com/whisper-money/whisper-money/issues/218)) ([1f5e6ac](https://github.com/whisper-money/whisper-money/commit/1f5e6ac450f0240020db92c369c30d291e01c512))
* **connections:** filter already-connected institutions from connect bank dialog ([#217](https://github.com/whisper-money/whisper-money/issues/217)) ([1058904](https://github.com/whisper-money/whisper-money/commit/1058904b14ac82df7dd1a1e8848b08b1ca64a143))
* **dashboard:** sort net worth chart accounts by average balance ([#219](https://github.com/whisper-money/whisper-money/issues/219)) ([b1cf133](https://github.com/whisper-money/whisper-money/commit/b1cf133b5ae059a4aa830195d412a77796f66530))
* **emails:** co-founder language, welcome rewrite, and Spanish translations ([#208](https://github.com/whisper-money/whisper-money/issues/208)) ([8ca4c8d](https://github.com/whisper-money/whisper-money/commit/8ca4c8d6c685fe214941dea4374f8af9dc30e7ac))
* **landing:** billing period toggle with yearly discount on pricing section ([#215](https://github.com/whisper-money/whisper-money/issues/215)) ([e9572e4](https://github.com/whisper-money/whisper-money/commit/e9572e4031416a5daa982a4f87e9615157ccd29d))
* **landing:** open-banking feature section with conditional grid layout ([#209](https://github.com/whisper-money/whisper-money/issues/209)) ([93369d8](https://github.com/whisper-money/whisper-money/commit/93369d8b6fb378cd16b086a1c65fd31dbd519350))
* **pricing:** update landing page pricing table ([#207](https://github.com/whisper-money/whisper-money/issues/207)) ([21b03c7](https://github.com/whisper-money/whisper-money/commit/21b03c7c36a9017cc899a67cfcf3b01a54be5920))

## [0.1.17](https://github.com/whisper-money/whisper-money/compare/v0.1.16...v0.1.17) (2026-03-05)


### Bug Fixes

* **amount-display:** eliminate float round-trip causing missing thousands separator ([#191](https://github.com/whisper-money/whisper-money/issues/191)) ([956b661](https://github.com/whisper-money/whisper-money/commit/956b6614486b48c43f4171ec0e4336409490ff34))
* **billing:** create Stripe customer before redirecting to billing portal ([#206](https://github.com/whisper-money/whisper-money/issues/206)) ([e8bc5fd](https://github.com/whisper-money/whisper-money/commit/e8bc5fd7866afab83dc0b807fdda8f6b3a0b1cc8))
* **browser-test:** reload transactions in syncing step and fix Skip button selector ([#203](https://github.com/whisper-money/whisper-money/issues/203)) ([3f6c676](https://github.com/whisper-money/whisper-money/commit/3f6c67631be95310cfee77bb2bed52d26ba74896)), closes [#201](https://github.com/whisper-money/whisper-money/issues/201)
* **haptics:** restore haptic feedback to MobileBackButton ([#198](https://github.com/whisper-money/whisper-money/issues/198)) ([fdc9d14](https://github.com/whisper-money/whisper-money/commit/fdc9d14c47c5e4d2eda9264592b1d7387dee6330))
* **i18n:** fix unlocalised string in create budget form ([#187](https://github.com/whisper-money/whisper-money/issues/187)) ([40a7942](https://github.com/whisper-money/whisper-money/commit/40a7942b85b0c145e21a1856ce40f86e89dc427d))
* **i18n:** force thousands separator for 4-digit amounts in es-ES locale ([#193](https://github.com/whisper-money/whisper-money/issues/193)) ([be2e205](https://github.com/whisper-money/whisper-money/commit/be2e205965eb2afbee4c7457c2f8a84d2356177f))
* **migration:** make add_waitlist_columns migration idempotent ([#200](https://github.com/whisper-money/whisper-money/issues/200)) ([cf9071c](https://github.com/whisper-money/whisper-money/commit/cf9071c11b237579b4f44de69dd688a3fcdd94b6))
* **onboarding:** gate connect bank option behind open-banking feature flag ([#197](https://github.com/whisper-money/whisper-money/issues/197)) ([09d81ac](https://github.com/whisper-money/whisper-money/commit/09d81ac7e7f2ebee953a85894d44a6848284d400))
* **static-analysis:** clear phpstan-baseline by fixing all suppressed errors ([#183](https://github.com/whisper-money/whisper-money/issues/183)) ([3e087bd](https://github.com/whisper-money/whisper-money/commit/3e087bdcd77ec638ee9d9dbb0d616b0ef78ff554))
* **testcontainers:** stop and remove MySQL container on signal and shutdown ([#202](https://github.com/whisper-money/whisper-money/issues/202)) ([011ba13](https://github.com/whisper-money/whisper-money/commit/011ba131142fb4e587ae5609d7ecab15c2b88796))
* **transactions:** move clear button inline with filters row on all screen sizes ([#192](https://github.com/whisper-money/whisper-money/issues/192)) ([b455ad7](https://github.com/whisper-money/whisper-money/commit/b455ad71ddc9b100107fcf67b7b78f907f698de5))


### Features

* (Onboarding) add categorization intro screen with benefit cards ([#201](https://github.com/whisper-money/whisper-money/issues/201)) ([a8dfac1](https://github.com/whisper-money/whisper-money/commit/a8dfac14226e90eac2a396236cd433d5d38501fb))
* **budgets:** make budget title clickable with muted hover effect ([#186](https://github.com/whisper-money/whisper-money/issues/186)) ([970e858](https://github.com/whisper-money/whisper-money/commit/970e85814e108b995711926e4c80e5580fa2736d))
* **dashboard:** make top spending categories clickable with transaction filter link ([#189](https://github.com/whisper-money/whisper-money/issues/189)) ([832fc61](https://github.com/whisper-money/whisper-money/commit/832fc6177e7f8ff337b79170e76e4cd53ea99e95))
* **haptics:** add haptic feedback to nav items and back buttons ([#196](https://github.com/whisper-money/whisper-money/issues/196)) ([3d74267](https://github.com/whisper-money/whisper-money/commit/3d742677b59f36fc6266adbe0904b7230387e6eb))
* **mobile:** add scroll-aware back button on detail pages ([#194](https://github.com/whisper-money/whisper-money/issues/194)) ([7fec851](https://github.com/whisper-money/whisper-money/commit/7fec8514e47d38f7d0ec253164d241703c0281d0))
* **onboarding:** inline connected account flow with auto-account creation and step deep-linking ([#184](https://github.com/whisper-money/whisper-money/issues/184)) ([993c91a](https://github.com/whisper-money/whisper-money/commit/993c91a6b6f1f65ee200c96764ecb8c0ad2fbdc6))
* **pricing:** dynamic Stripe pricing with locale-aware formatting ([#204](https://github.com/whisper-money/whisper-money/issues/204)) ([ac1476e](https://github.com/whisper-money/whisper-money/commit/ac1476eeffee91a67bd91443c5a10b4c46576275))
* **privacy:** enable privacy mode for all users and extend amount masking ([#182](https://github.com/whisper-money/whisper-money/issues/182)) ([152b186](https://github.com/whisper-money/whisper-money/commit/152b186c103458e8d7833034027750a663555906))
* **subscription:** allow free plan for open banking users without connected banks ([#188](https://github.com/whisper-money/whisper-money/issues/188)) ([d8f6a68](https://github.com/whisper-money/whisper-money/commit/d8f6a680ceb3a11ed215e4bdb969e0a18fa74833))
* **waitlist:** waiting list with referral system ([#199](https://github.com/whisper-money/whisper-money/issues/199)) ([4d0d203](https://github.com/whisper-money/whisper-money/commit/4d0d203fd373df5608d6a15dd3da0980c5c49502)), closes [#500](https://github.com/whisper-money/whisper-money/issues/500)

## [0.1.16](https://github.com/whisper-money/whisper-money/compare/v0.1.14...v0.1.16) (2026-03-01)


### Bug Fixes

* **i18n:** localise missing strings in budget dialogs to Spanish ([#177](https://github.com/whisper-money/whisper-money/issues/177)) ([7260525](https://github.com/whisper-money/whisper-money/commit/7260525890a9ca94bbecdf7e38c6ce81e5f900ee))
* **i18n:** localize billing settings page into Spanish ([#176](https://github.com/whisper-money/whisper-money/issues/176)) ([7a8eda9](https://github.com/whisper-money/whisper-money/commit/7a8eda9d905ec3dc771671a474f566a0205aa87d))
* **i18n:** localize mobile bottom navigation labels into Spanish ([#173](https://github.com/whisper-money/whisper-money/issues/173)) ([717bf34](https://github.com/whisper-money/whisper-money/commit/717bf34103855cdb7c39fb6fab2559f8f797782e))
* Missing space between page sections and create button ([6c5961d](https://github.com/whisper-money/whisper-money/commit/6c5961da050b3548134f685e5b591f8dc314481e))
* **tooling:** fix stringWidth error in release-it interactive prompt ([#179](https://github.com/whisper-money/whisper-money/issues/179)) ([866f908](https://github.com/whisper-money/whisper-money/commit/866f90838e4e9be8c3bccb1034ae339868c60a4c))
* **transactions:** fix toolbar overflow on mobile and shorten button label ([#175](https://github.com/whisper-money/whisper-money/issues/175)) ([0388705](https://github.com/whisper-money/whisper-money/commit/0388705c1236e0398f1c8246ce6426e76f27c6ee))


### Features

* **Budgets:** add period navigation and unify period selector UI ([#171](https://github.com/whisper-money/whisper-money/issues/171)) ([0493b87](https://github.com/whisper-money/whisper-money/commit/0493b87562ac0d66aa933e4b77863265b7c72e24))
* **i18n:** add localization test and fix missing Spanish translations ([#174](https://github.com/whisper-money/whisper-money/issues/174)) ([9317238](https://github.com/whisper-money/whisper-money/commit/9317238c49269f99e6689e580f1bea0f0f28288a))
* **nav:** add icon+label mobile nav with active pill and full-width buttons ([#178](https://github.com/whisper-money/whisper-money/issues/178)) ([efd86bc](https://github.com/whisper-money/whisper-money/commit/efd86bc8d7e3aca3b433bc3d880f8c279d790f8c))
* **rules:** move automation rule evaluation to the backend ([#168](https://github.com/whisper-money/whisper-money/issues/168)) ([eda72d4](https://github.com/whisper-money/whisper-money/commit/eda72d4304948fb73094195fb71509d0b08c8f67))
* **transactions:** re-add select all matching filters to bulk actions bar ([#169](https://github.com/whisper-money/whisper-money/issues/169)) ([0d9fc5a](https://github.com/whisper-money/whisper-money/commit/0d9fc5a2b9243c0d449f497c12b2978038fdf42a))
* **ui:** add create buttons to accounts and budgets pages ([#172](https://github.com/whisper-money/whisper-money/issues/172)) ([9f5e62f](https://github.com/whisper-money/whisper-money/commit/9f5e62f736803a43467673c635758143caac7f48))
* **ui:** add glowing effect to all card components ([#170](https://github.com/whisper-money/whisper-money/issues/170)) ([4d14e4d](https://github.com/whisper-money/whisper-money/commit/4d14e4d2f0c006245bcb473ac8a0b11930dee460))

## [0.1.14](https://github.com/whisper-money/whisper-money/compare/v0.1.13...v0.1.14) (2026-03-01)


### Bug Fixes

* **accounts:** widen bank column and truncate text on mobile ([#163](https://github.com/whisper-money/whisper-money/issues/163)) ([e01d62f](https://github.com/whisper-money/whisper-money/commit/e01d62ffd46861270581102da969c7cda12397b9))
* **categorizer:** fetch uncategorized transactions from backend instead of IndexedDB ([#165](https://github.com/whisper-money/whisper-money/issues/165)) ([9bb835e](https://github.com/whisper-money/whisper-money/commit/9bb835e79b02182679b111a439171eb71e427010))
* **i18n:** fix missing space after Tracking label and add account/accounts Spanish translations ([#167](https://github.com/whisper-money/whisper-money/issues/167)) ([cd0da10](https://github.com/whisper-money/whisper-money/commit/cd0da10014373af5d3f6ff3298c0fd8247adccb2))
* prevent gain/loss sign from wrapping off the amount ([#158](https://github.com/whisper-money/whisper-money/issues/158)) ([a4d2100](https://github.com/whisper-money/whisper-money/commit/a4d2100459fde6a2f9a2becdd0807a0eae3dfd65)), closes [whisper-money/whisper-money#157](https://github.com/whisper-money/whisper-money/issues/157)
* **ui:** app icon visible on light wallpapers + country select overflow on mobile ([#162](https://github.com/whisper-money/whisper-money/issues/162)) ([1b7b147](https://github.com/whisper-money/whisper-money/commit/1b7b147832f90894b6bb4806e0295853a458f296))
* **ux:** improve status badge, hide balance update for connected accounts, localize delete confirm ([#159](https://github.com/whisper-money/whisper-money/issues/159)) ([79dd24b](https://github.com/whisper-money/whisper-money/commit/79dd24b23ef8ebd9594df9af8da1007e7f3f0f6e))


### Features

* **automation-rules:** simplify smart rules UI, fix re-evaluation, and localize amounts ([#161](https://github.com/whisper-money/whisper-money/issues/161)) ([b1f01e4](https://github.com/whisper-money/whisper-money/commit/b1f01e4a8f3eedc9e5848cdda103bdc06c3ce571))
* **cashflow:** promote trend chart above money flow and increase height ([#166](https://github.com/whisper-money/whisper-money/issues/166)) ([39a47ec](https://github.com/whisper-money/whisper-money/commit/39a47ec23ff52e609825cbd8ebdfa9576b0df22e)), closes [hi#value](https://github.com/hi/issues/value)
* **categories:** add Self-Employment Income income category ([#164](https://github.com/whisper-money/whisper-money/issues/164)) ([77b225d](https://github.com/whisper-money/whisper-money/commit/77b225d74795bbb1c18e526bd38dfa3859ecac44))
* **i18n:** localize Spanish translations and currency formatting ([#160](https://github.com/whisper-money/whisper-money/issues/160)) ([2b9fd23](https://github.com/whisper-money/whisper-money/commit/2b9fd2384a3a06f0132498b8fda3ae50624f25d9))

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
