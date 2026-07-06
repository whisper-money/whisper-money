# Changelog

## [0.2.6](https://github.com/whisper-money/whisper-money/compare/v0.2.5...v0.2.6) (2026-07-06)


### Bug Fixes

* **accounts:** fire drag haptic on first tap, not after dragging ([#576](https://github.com/whisper-money/whisper-money/issues/576)) ([c75e834](https://github.com/whisper-money/whisper-money/commit/c75e834b89b58f19fba0021e423ef5d7fe8ae827))
* **accounts:** remove double-skeleton flash on account show ([#634](https://github.com/whisper-money/whisper-money/issues/634)) ([afa80b6](https://github.com/whisper-money/whisper-money/commit/afa80b60fe97ecc504847ef9c4a1e8c9a9c2c9f1)), closes [#632](https://github.com/whisper-money/whisper-money/issues/632)
* **accounts:** stop second long-press haptic on drag handle ([#578](https://github.com/whisper-money/whisper-money/issues/578)) ([5db6cdc](https://github.com/whisper-money/whisper-money/commit/5db6cdc6d2fcc03d3d89d16d058834cb89107999)), closes [#576](https://github.com/whisper-money/whisper-money/issues/576)
* address remaining security audit findings (round 2) ([#628](https://github.com/whisper-money/whisper-money/issues/628)) ([4fdb7ee](https://github.com/whisper-money/whisper-money/commit/4fdb7eebd916f81bfde7dc4c6adbc8387d6699ca)), closes [#623](https://github.com/whisper-money/whisper-money/issues/623)
* **ai:** handle transient AI provider overloads — stop the Sentry noise and retry the dropped work ([#595](https://github.com/whisper-money/whisper-money/issues/595)) ([4038e60](https://github.com/whisper-money/whisper-money/commit/4038e60fbcd9891ceecabe5f66c34dde1abcc6cd))
* **ai:** surface learned-rule toast in edit modal and guard weak description keys ([#635](https://github.com/whisper-money/whisper-money/issues/635)) ([c159e87](https://github.com/whisper-money/whisper-money/commit/c159e8782efa5808e1f707eb7309966de3144f9b))
* **analysis:** respect category types like the cashflow screen ([#612](https://github.com/whisper-money/whisper-money/issues/612)) ([986f437](https://github.com/whisper-money/whisper-money/commit/986f43705a35fb0e3cd49c2056c2a46e769a91aa))
* **appearance:** support MediaQueryList change events on legacy Safari (PHP-LARAVEL-41) ([#646](https://github.com/whisper-money/whisper-money/issues/646)) ([465eb38](https://github.com/whisper-money/whisper-money/commit/465eb38dae1e856f0017f7f0e33bbad31b1d2c71))
* **banking:** handle EnableBanking expired sessions as reconnect, not error ([#557](https://github.com/whisper-money/whisper-money/issues/557)) ([c36df98](https://github.com/whisper-money/whisper-money/commit/c36df98d326336c27cf63039eb28518dae3af43e))
* **banking:** keep the native green Wise logo, not the aggregator's ([#590](https://github.com/whisper-money/whisper-money/issues/590)) ([578a9b4](https://github.com/whisper-money/whisper-money/commit/578a9b44d8f01d4d22558397af2f31a9e5bf80b8)), closes [#589](https://github.com/whisper-money/whisper-money/issues/589) [#525](https://github.com/whisper-money/whisper-money/issues/525) [#589](https://github.com/whisper-money/whisper-money/issues/589)
* **banking:** only log sync failures once the connection gives up ([#603](https://github.com/whisper-money/whisper-money/issues/603)) ([8bbff05](https://github.com/whisper-money/whisper-money/commit/8bbff05b2693cef3edbc8fc4c9350f6ba7ab99d6))
* **banking:** skip inaccessible EnableBanking accounts instead of failing the connection ([#559](https://github.com/whisper-money/whisper-money/issues/559)) ([4656870](https://github.com/whisper-money/whisper-money/commit/46568700b2c0610566a7a35efe54810ea51e3925))
* **banking:** stop Wise appearing multiple times in the connect list ([#589](https://github.com/whisper-money/whisper-money/issues/589)) ([ed5aac0](https://github.com/whisper-money/whisper-money/commit/ed5aac0c4a0713e5bf2b0f2a82b9258abdcdb986))
* **charts:** improve contrast for chart colors 9-10 ([#614](https://github.com/whisper-money/whisper-money/issues/614)) ([a37481f](https://github.com/whisper-money/whisper-money/commit/a37481fb71b935f0f42b282fabe6db3c664be5c6)), closes [hi#index](https://github.com/hi/issues/index)
* **discord:** show old → new plan on plan change notification ([#637](https://github.com/whisper-money/whisper-money/issues/637)) ([3972007](https://github.com/whisper-money/whisper-money/commit/39720078447f9b17dacbfd15e9986f978b87b56a))
* **discord:** skip zero-amount payment stats messages ([#540](https://github.com/whisper-money/whisper-money/issues/540)) ([7693e48](https://github.com/whisper-money/whisper-money/commit/7693e4813f810c21836b292770590ec511224109))
* **i18n:** keep Discord brand name untranslated in French ([#541](https://github.com/whisper-money/whisper-money/issues/541)) ([06effb5](https://github.com/whisper-money/whisper-money/commit/06effb5e6ef2311657c5beaf9ec57918739eb38f))
* **integration-requests:** freeze votes on not-doable requests ([#555](https://github.com/whisper-money/whisper-money/issues/555)) ([89c1ab1](https://github.com/whisper-money/whisper-money/commit/89c1ab1ca8edf4afcab183e11d0b6fc7ab095952))
* **open-banking:** only block re-adding a bank when a live connection exists ([#569](https://github.com/whisper-money/whisper-money/issues/569)) ([14c4598](https://github.com/whisper-money/whisper-money/commit/14c4598cda6989017af9dd8551791d5a2c456a9d))
* **open-banking:** stop storing the XXX no-currency placeholder on accounts ([#602](https://github.com/whisper-money/whisper-money/issues/602)) ([bc57eae](https://github.com/whisper-money/whisper-money/commit/bc57eae5c34cdf37beb7a82b5569fb50d12e3ab7))
* **queue:** add supervisor worker for the ai queue ([#546](https://github.com/whisper-money/whisper-money/issues/546)) ([f191d74](https://github.com/whisper-money/whisper-money/commit/f191d740314836ae12b897b6e9f5151ecc39e15f))
* **queue:** raise retry_after above the longest job timeout (PHP-LARAVEL-2D) ([#645](https://github.com/whisper-money/whisper-money/issues/645)) ([05d4bae](https://github.com/whisper-money/whisper-money/commit/05d4bae0af1fece9196fabde61a624cf19472da9))
* **security:** scope job-status endpoints to owner + feature-area fixes ([#627](https://github.com/whisper-money/whisper-money/issues/627)) ([d55c3f4](https://github.com/whisper-money/whisper-money/commit/d55c3f41df9705fe533d13f3fa9da0cf7790cb4a)), closes [#4](https://github.com/whisper-money/whisper-money/issues/4) [#1](https://github.com/whisper-money/whisper-money/issues/1)
* **sentry:** drop browser-extension noise before sending events ([#568](https://github.com/whisper-money/whisper-money/issues/568)) ([52708f9](https://github.com/whisper-money/whisper-money/commit/52708f940df2a86bc1bf47afe72af08abd4e345f))
* **settings:** vertically center rows in automation rules and labels tables ([#615](https://github.com/whisper-money/whisper-money/issues/615)) ([e631cbb](https://github.com/whisper-money/whisper-money/commit/e631cbba69d8184f5efbb4c65d198a836a9ab883))
* skip demo reset when demo account is disabled ([#626](https://github.com/whisper-money/whisper-money/issues/626)) ([eb31455](https://github.com/whisper-money/whisper-money/commit/eb31455e606f8e0b965b6b3cd83d4e2c9de34df5))
* stop double-dispatching transaction listeners (N+1 insert into jobs) ([#620](https://github.com/whisper-money/whisper-money/issues/620)) ([0f8eca5](https://github.com/whisper-money/whisper-money/commit/0f8eca50d036aca21142fac39ca5ed385d2dbada))
* **transactions:** let date column size to its content ([#610](https://github.com/whisper-money/whisper-money/issues/610)) ([5ef3e01](https://github.com/whisper-money/whisper-money/commit/5ef3e01c8905795ca29600bab3f62578ffe0673d))
* **transactions:** only auto-select account on account pages ([#549](https://github.com/whisper-money/whisper-money/issues/549)) ([9a20335](https://github.com/whisper-money/whisper-money/commit/9a20335c6a4a7fa814f1460afbf8384888ee2e88))
* **transactions:** pad Category column when Date column is hidden ([#584](https://github.com/whisper-money/whisper-money/issues/584)) ([d2806b5](https://github.com/whisper-money/whisper-money/commit/d2806b5887753aca2d7ccce8b2360107541890bc)), closes [#583](https://github.com/whisper-money/whisper-money/issues/583) [#582](https://github.com/whisper-money/whisper-money/issues/582)
* **transactions:** pad Category column when Date column is hidden ([#585](https://github.com/whisper-money/whisper-money/issues/585)) ([8e38713](https://github.com/whisper-money/whisper-money/commit/8e3871370ae17e2b582effdb72218ba2ed65b40a)), closes [582/#583](https://github.com/whisper-money/whisper-money/issues/583)
* **transactions:** restore left padding when category is first column ([#582](https://github.com/whisper-money/whisper-money/issues/582)) ([d11aa2d](https://github.com/whisper-money/whisper-money/commit/d11aa2dfe579e0a9af27909d51ac776f975b3073))
* **ui:** make input borders visible in dark mode ([#571](https://github.com/whisper-money/whisper-money/issues/571)) ([83a5e96](https://github.com/whisper-money/whisper-money/commit/83a5e9657e679d0e849c9f5f532a021dce9807ff))
* **worktree:** remove double slash in storage/keys copy path ([#629](https://github.com/whisper-money/whisper-money/issues/629)) ([4615d7a](https://github.com/whisper-money/whisper-money/commit/4615d7a88002f2b3c7df847a27f783a710a69cc6))


### Features

* **accounts:** reorder accounts with drag-and-drop ([#575](https://github.com/whisper-money/whisper-money/issues/575)) ([cd3080e](https://github.com/whisper-money/whisper-money/commit/cd3080ec52fd2586f9920794559c7b500cbef6bf))
* add Wise open banking integration with balance sync ([#525](https://github.com/whisper-money/whisper-money/issues/525)) ([1c5a76a](https://github.com/whisper-money/whisper-money/commit/1c5a76a3a4cc9173d7ba2a7f8e3f67fc303e30d4))
* **ai:** dismissable AI consent banner that stops after the first decision ([#617](https://github.com/whisper-money/whisper-money/issues/617)) ([7e36bba](https://github.com/whisper-money/whisper-money/commit/7e36bbafef8faa076b25dc70451b7165b000349a))
* **ai:** learn from category corrections so the AI stops repeating the same mistake ([#608](https://github.com/whisper-money/whisper-money/issues/608)) ([6727a9c](https://github.com/whisper-money/whisper-money/commit/6727a9c64a7083ac962e4d489a36c61f4c4e590f))
* **ai:** manage AI consent outside onboarding with live backfill ([#591](https://github.com/whisper-money/whisper-money/issues/591)) ([9a458b1](https://github.com/whisper-money/whisper-money/commit/9a458b103131a0b848bdc17b5d015c923a30d71e))
* **ai:** persist AI categorization suggestions below the label bar ([#547](https://github.com/whisper-money/whisper-money/issues/547)) ([9328cd3](https://github.com/whisper-money/whisper-money/commit/9328cd3e1ba53218b521d6501d8750bb483a2ea6))
* **ai:** record the model behind each AI categorization ([#594](https://github.com/whisper-money/whisper-money/issues/594)) ([291cfbe](https://github.com/whisper-money/whisper-money/commit/291cfbe2612a3682f913216a64ebc76c699e55a2))
* **banking:** add Interactive Brokers sync via Flex Web Service ([#581](https://github.com/whisper-money/whisper-money/issues/581)) ([f60e6d7](https://github.com/whisper-money/whisper-money/commit/f60e6d70355d77b05ff4cad690cea65c1eb5792d))
* **banking:** enable Interactive Brokers for all users ([#593](https://github.com/whisper-money/whisper-money/issues/593)) ([094ff4d](https://github.com/whisper-money/whisper-money/commit/094ff4d5ac1e4ebab787056b8794654ac49f0cd8))
* **banking:** let Wise credentials be updated ([#588](https://github.com/whisper-money/whisper-money/issues/588)) ([619ed0f](https://github.com/whisper-money/whisper-money/commit/619ed0f1db44f004b8e4704e2a4e1bbef0e56a74)), closes [#581](https://github.com/whisper-money/whisper-money/issues/581)
* **connections:** create a new account from the manage-accounts selector ([#560](https://github.com/whisper-money/whisper-money/issues/560)) ([6cb8d11](https://github.com/whisper-money/whisper-money/commit/6cb8d115631d41d01f0dee025092decc4c31e01c))
* **connections:** manage which accounts a bank connection syncs ([#558](https://github.com/whisper-money/whisper-money/issues/558)) ([a9b90a2](https://github.com/whisper-money/whisper-money/commit/a9b90a200efc66d85e20405872a857db829255cf))
* **currencies:** add Nigerian Naira (NGN) ([#642](https://github.com/whisper-money/whisper-money/issues/642)) ([6ff7edf](https://github.com/whisper-money/whisper-money/commit/6ff7edf193bfb9baf51b4907a9fe0da49a95f533))
* **currency:** add GHS (Ghanaian Cedi) ([#644](https://github.com/whisper-money/whisper-money/issues/644)) ([2aebe45](https://github.com/whisper-money/whisper-money/commit/2aebe45d1f5a481760fd2c36ec98436b29d1c510)), closes [#567](https://github.com/whisper-money/whisper-money/issues/567)
* **currency:** add RSD (Serbian Dinar) ([#567](https://github.com/whisper-money/whisper-money/issues/567)) ([934e16c](https://github.com/whisper-money/whisper-money/commit/934e16c0fa2659a7e701d6cfcc23cbaad67d0c13))
* **dashboard:** add accounts manager dialog with visibility toggle and reorder ([#604](https://github.com/whisper-money/whisper-money/issues/604)) ([777dfc0](https://github.com/whisper-money/whisper-money/commit/777dfc07b281a5df522fc93154b73e6d7c72d2ef))
* **demo:** gate demo account access behind a config flag ([#580](https://github.com/whisper-money/whisper-money/issues/580)) ([a346566](https://github.com/whisper-money/whisper-money/commit/a346566fd0a6398bb7e9b146617f88d0d218a35f))
* **drip:** email users stuck on the paywall a day after onboarding ([#562](https://github.com/whisper-money/whisper-money/issues/562)) ([ce6bfc9](https://github.com/whisper-money/whisper-money/commit/ce6bfc9c562195c7dc89028fe3a920a814ff1b7a))
* **email:** follow up after post-onboarding AI consent ([#596](https://github.com/whisper-money/whisper-money/issues/596)) ([934d834](https://github.com/whisper-money/whisper-money/commit/934d834ab3dd19d66525fbd883d30de1b3d77982))
* **encryption:** commands to warn and remove inactive encrypted-data accounts ([#633](https://github.com/whisper-money/whisper-money/issues/633)) ([477e4d5](https://github.com/whisper-money/whisper-money/commit/477e4d50e26760d387dee1784db7093c05ce593e))
* **features:** support percentage rollouts in feature:enable ([#592](https://github.com/whisper-money/whisper-money/issues/592)) ([f72e2a6](https://github.com/whisper-money/whisper-money/commit/f72e2a64ca1101a04ddc3c3384f5f7c75aed8e5d))
* **i18n:** add French translation support ([#532](https://github.com/whisper-money/whisper-money/issues/532)) ([a38ed69](https://github.com/whisper-money/whisper-money/commit/a38ed69d2e134651ceddae5082fd2d70493b83e0))
* **integration-requests:** add done status and fix review command crash on orphaned author ([#601](https://github.com/whisper-money/whisper-money/issues/601)) ([e4be39b](https://github.com/whisper-money/whisper-money/commit/e4be39be1201b54894097e9580958d7cd23c4740))
* **integration-requests:** add not-doable status with a public comment ([#552](https://github.com/whisper-money/whisper-money/issues/552)) ([801f6c7](https://github.com/whisper-money/whisper-money/commit/801f6c7cd4834eeecbe77a078994c89845003a70))
* **integration-requests:** community board to request & vote bank integrations ([#550](https://github.com/whisper-money/whisper-money/issues/550)) ([5e8f227](https://github.com/whisper-money/whisper-money/commit/5e8f227fbdabbd15fd752645e7a1405803f032d9))
* **integration-requests:** let the admin bypass limits and auto-approve ([#551](https://github.com/whisper-money/whisper-money/issues/551)) ([7e9122e](https://github.com/whisper-money/whisper-money/commit/7e9122eaf442f0ea4cd2f2eecf1dfea1c8e7f7fd))
* **integration-requests:** markdown comments and in-progress status ([#553](https://github.com/whisper-money/whisper-money/issues/553)) ([da88adb](https://github.com/whisper-money/whisper-money/commit/da88adbee36c899fa24342d0b62c0cf1063df689))
* **integration-requests:** multi-vote, per-plan quota and undo ([#554](https://github.com/whisper-money/whisper-money/issues/554)) ([0ea54fa](https://github.com/whisper-money/whisper-money/commit/0ea54fa0d75dd268c7cb5e59f1da95a9a22976ee))
* **landing:** clarify AI framing and add testimonials ([#613](https://github.com/whisper-money/whisper-money/issues/613)) ([d55e15b](https://github.com/whisper-money/whisper-money/commit/d55e15bb4faabf34e4f1f92022db4a17fe085dec))
* **onboarding:** auto-enable AI for connected banks, ask the rest ([#618](https://github.com/whisper-money/whisper-money/issues/618)) ([10442c1](https://github.com/whisper-money/whisper-money/commit/10442c1e3293270dcc75a299414b793c5db14610))
* **onboarding:** clarify the "categorize at least 5" goal in the categorizer ([#616](https://github.com/whisper-money/whisper-money/issues/616)) ([af64f56](https://github.com/whisper-money/whisper-money/commit/af64f563991897723c69749ac69bf724b162f44c))
* **open-banking:** allow re-connecting a bank behind a replace warning ([#570](https://github.com/whisper-money/whisper-money/issues/570)) ([64827fa](https://github.com/whisper-money/whisper-money/commit/64827fabae82cadd98febdd457dcf0106934cc18)), closes [#569](https://github.com/whisper-money/whisper-money/issues/569)
* **open-banking:** disable already-connected banks in the connect picker ([#556](https://github.com/whisper-money/whisper-money/issues/556)) ([6e6433c](https://github.com/whisper-money/whisper-money/commit/6e6433c6ad8c6673edc5f263a76096bc4377da96))
* **open-banking:** enable manage bank accounts for everyone ([#572](https://github.com/whisper-money/whisper-money/issues/572)) ([0f3cdd4](https://github.com/whisper-money/whisper-money/commit/0f3cdd41aaa72a9544202a3d5add2515cf5ac6ab))
* **paywall:** require a plan when the user has accepted AI ([#564](https://github.com/whisper-money/whisper-money/issues/564)) ([29d13ce](https://github.com/whisper-money/whisper-money/commit/29d13ceed103860399b271ffed3cb17810112001))
* **stats:** add --no-discord flag to stats:experiment-funnel ([#606](https://github.com/whisper-money/whisper-money/issues/606)) ([1db2871](https://github.com/whisper-money/whisper-money/commit/1db2871398bd86deb0a3c48ba1f1881822e016cc))
* **stats:** add --no-discord to the remaining report commands ([#607](https://github.com/whisper-money/whisper-money/issues/607)) ([300756e](https://github.com/whisper-money/whisper-money/commit/300756e55341b84245efbbd37a038ae7edb7217b))
* **stats:** add weekly subscription funnel report ([#599](https://github.com/whisper-money/whisper-money/issues/599)) ([756b481](https://github.com/whisper-money/whisper-money/commit/756b4816a6d67922a15cf6b69e9933f8f1bea2c8))
* **stats:** weekly paywall stuck-cohort report to Discord ([#563](https://github.com/whisper-money/whisper-money/issues/563)) ([57f8c93](https://github.com/whisper-money/whisper-money/commit/57f8c93e2818ad53abcd8d1ad656aeb1f1edda4d))
* **subscriptions:** reframe pay_now paywall copy around try-and-refund ([#605](https://github.com/whisper-money/whisper-money/issues/605)) ([09d6e8e](https://github.com/whisper-money/whisper-money/commit/09d6e8ee6cfe6247439bfb3bfd475ab1a092e722))
* **subscriptions:** trial/pricing A/B/C experiment ([#600](https://github.com/whisper-money/whisper-money/issues/600)) ([e5350ff](https://github.com/whisper-money/whisper-money/commit/e5350ff1a6061ee3bdb9596e3b6e925618e39e3e))
* **support:** add support link with community-first help modal ([#542](https://github.com/whisper-money/whisper-money/issues/542)) ([4a891a5](https://github.com/whisper-money/whisper-money/commit/4a891a5906d57f17bbf0aeeec957656e7e67f937))
* **transactions:** default balance toggle on and apply it server-side ([#566](https://github.com/whisper-money/whisper-money/issues/566)) ([b76a0de](https://github.com/whisper-money/whisper-money/commit/b76a0de0746e334835c84e7aef96140e8ef7d00f))
* **transactions:** highlight new transactions since last visit ([#609](https://github.com/whisper-money/whisper-money/issues/609)) ([884038c](https://github.com/whisper-money/whisper-money/commit/884038c13bd093ca5ec1f6a656af40453f085efc))
* **transactions:** make new-transaction marker cross-device ([#611](https://github.com/whisper-money/whisper-money/issues/611)) ([ee69c51](https://github.com/whisper-money/whisper-money/commit/ee69c51a846a944632e6c23e098014f768229310)), closes [#609](https://github.com/whisper-money/whisper-money/issues/609)
* **transactions:** refine new transaction form layout and balance toggle ([#597](https://github.com/whisper-money/whisper-money/issues/597)) ([d6ec983](https://github.com/whisper-money/whisper-money/commit/d6ec9830dff95b1fa869b57604ccc892e97113bb))
* **transactions:** release transaction analysis to all users ([#579](https://github.com/whisper-money/whisper-money/issues/579)) ([ae6f869](https://github.com/whisper-money/whisper-money/commit/ae6f8696118cc9d4808a8ee9270de2b25850dd70))
* **transactions:** reorder filters and switch accounts to a logo dropdown ([#598](https://github.com/whisper-money/whisper-money/issues/598)) ([d7bc4e6](https://github.com/whisper-money/whisper-money/commit/d7bc4e6707a802044b5f931c54cefca23dcbeebc))
* **transactions:** serve import dedup and account ledger from the backend ([#631](https://github.com/whisper-money/whisper-money/issues/631)) ([021cb66](https://github.com/whisper-money/whisper-money/commit/021cb6664311ec475e87b628ca7a88baf8de4907))
* **welcome:** add Francisco Montes testimonial ([#636](https://github.com/whisper-money/whisper-money/issues/636)) ([02087ab](https://github.com/whisper-money/whisper-money/commit/02087abcc7c7e9f7c210ae922c7813206db6093e))
* **welcome:** add Haru testimonial with Discord avatar ([#577](https://github.com/whisper-money/whisper-money/issues/577)) ([b0e74fa](https://github.com/whisper-money/whisper-money/commit/b0e74fac2c629078d4d88b9a2365d343571cc0e6))


### Performance Improvements

* **accounts:** defer the account ledger prop on show ([#632](https://github.com/whisper-money/whisper-money/issues/632)) ([9326d8f](https://github.com/whisper-money/whisper-money/commit/9326d8fd2fd3c9739cffa7d0f2f384fa49a623c1)), closes [#631](https://github.com/whisper-money/whisper-money/issues/631) [#631](https://github.com/whisper-money/whisper-money/issues/631) [#631](https://github.com/whisper-money/whisper-money/issues/631)
* **ai:** reduce N+1 in bulk category updates (PHP-LARAVEL-40, partial) ([#624](https://github.com/whisper-money/whisper-money/issues/624)) ([3d3f6da](https://github.com/whisper-money/whisper-money/commit/3d3f6daa77c130e5a91547d9426189a1e820cad5)), closes [#2](https://github.com/whisper-money/whisper-money/issues/2)
* **banking:** kill per-transaction dedup N+1 in bank sync (PHP-LARAVEL-3Y) ([#621](https://github.com/whisper-money/whisper-money/issues/621)) ([84bad76](https://github.com/whisper-money/whisper-money/commit/84bad76316425616899f03157fa8246144635288))
* **db:** index transactions for the daily synced-email slow query (PHP-LARAVEL-3X) ([#622](https://github.com/whisper-money/whisper-money/issues/622)) ([ad46e46](https://github.com/whisper-money/whisper-money/commit/ad46e465be3cb2d3a29726a7c4cc2f822ecf67a3))

## [0.2.5](https://github.com/whisper-money/whisper-money/compare/v0.2.4...v0.2.5) (2026-06-15)


### Bug Fixes

* **account:** block deletion while subscription or trial is active ([#531](https://github.com/whisper-money/whisper-money/issues/531)) ([2bfb569](https://github.com/whisper-money/whisper-money/commit/2bfb569a2226df44b71363e731889ab07a2a41c4)) by [@victor-falcon](https://github.com/victor-falcon)
* **analysis:** align summary card amounts to a common baseline ([#515](https://github.com/whisper-money/whisper-money/issues/515)) ([21f8f3b](https://github.com/whisper-money/whisper-money/commit/21f8f3b27719f3ff47b4769e5fdda00c323fe22d)) by [@victor-falcon](https://github.com/victor-falcon)
* **analysis:** truncate long breakdown names so amounts stay in widget ([#518](https://github.com/whisper-money/whisper-money/issues/518)) ([49acc8a](https://github.com/whisper-money/whisper-money/commit/49acc8a884617fc8a97e7b82e7dcedb5931a20b4)) by [@victor-falcon](https://github.com/victor-falcon)
* **auth:** prevent FormData crash on successful login ([#503](https://github.com/whisper-money/whisper-money/issues/503)) ([f4bbbfd](https://github.com/whisper-money/whisper-money/commit/f4bbbfd767388642b856b53814187b9c85e4ac22)) by [@victor-falcon](https://github.com/victor-falcon)
* **automation-rules:** hint amount sign for expenses vs income ([#524](https://github.com/whisper-money/whisper-money/issues/524)) ([e526f86](https://github.com/whisper-money/whisper-money/commit/e526f861b2a7f46a073273482f10111f06e44f84)) by [@victor-falcon](https://github.com/victor-falcon)
* **budgets:** make period generation idempotent ([#533](https://github.com/whisper-money/whisper-money/issues/533)) ([cd323bb](https://github.com/whisper-money/whisper-money/commit/cd323bbe529678e68bca23e84a9cdb0d78c33373)) by [@victor-falcon](https://github.com/victor-falcon)
* **cashflow:** bound trend window to prevent request timeout ([#534](https://github.com/whisper-money/whisper-money/issues/534)) ([1d4bcd5](https://github.com/whisper-money/whisper-money/commit/1d4bcd5082413071ddcc2764ab866e23ea73ab5a)) by [@victor-falcon](https://github.com/victor-falcon)
* **currency:** make rate fetching resilient to slow CDN ([#502](https://github.com/whisper-money/whisper-money/issues/502)) ([4b90bcf](https://github.com/whisper-money/whisper-money/commit/4b90bcfc96b4dd6340981d53f2e665d02872288f)) by [@victor-falcon](https://github.com/victor-falcon)
* **header:** keep mobile logo on one line, compact auth buttons ([#512](https://github.com/whisper-money/whisper-money/issues/512)) ([1530544](https://github.com/whisper-money/whisper-money/commit/1530544b8b3322f90829991df3d41d06e43e54a8)) by [@victor-falcon](https://github.com/victor-falcon)
* **import:** honor selected date format for CSV imports ([#494](https://github.com/whisper-money/whisper-money/issues/494)) ([744d874](https://github.com/whisper-money/whisper-money/commit/744d874464b803b4f9a187347cb833c9440a62d1)) by [@victor-falcon](https://github.com/victor-falcon)
* **layout:** keep bottom padding while floating nav is visible ([#537](https://github.com/whisper-money/whisper-money/issues/537)) ([6671d89](https://github.com/whisper-money/whisper-money/commit/6671d89ea10638983a5ba10a718ea16dde982697)) by [@victor-falcon](https://github.com/victor-falcon)
* **perf:** batch feature flag resolution in shared Inertia data ([#500](https://github.com/whisper-money/whisper-money/issues/500)) ([cb728ce](https://github.com/whisper-money/whisper-money/commit/cb728ce176fbac50984c183abae8af19acf1c8e7)) by [@victor-falcon](https://github.com/victor-falcon), closes [#496](https://github.com/whisper-money/whisper-money/issues/496)
* **sentry:** only report errors in production ([#467](https://github.com/whisper-money/whisper-money/issues/467)) ([d68fee6](https://github.com/whisper-money/whisper-money/commit/d68fee6c2dd54f923cb0a57c1dd483d5fa4e1ed5)) by [@victor-falcon](https://github.com/victor-falcon)
* **transactions:** combine category and label filters with OR ([#495](https://github.com/whisper-money/whisper-money/issues/495)) ([af87ac7](https://github.com/whisper-money/whisper-money/commit/af87ac75600060939b7c27e99548bace462a0d73)) by [@victor-falcon](https://github.com/victor-falcon)
* **transactions:** improve mobile analysis button affordance ([#520](https://github.com/whisper-money/whisper-money/issues/520)) ([3223824](https://github.com/whisper-money/whisper-money/commit/3223824edb03d3f5657af55dc8b01a8e79259f06)) by [@victor-falcon](https://github.com/victor-falcon)
* **transactions:** prevent crash when sorting by nullable column ([#501](https://github.com/whisper-money/whisper-money/issues/501)) ([a69c602](https://github.com/whisper-money/whisper-money/commit/a69c602366c5a2c8e18ad106827fc30693ba8471)) by [@victor-falcon](https://github.com/victor-falcon)
* **transactions:** tidy filter bar into two balanced rows ([#510](https://github.com/whisper-money/whisper-money/issues/510)) ([6486908](https://github.com/whisper-money/whisper-money/commit/6486908716e563b3b8970b9ec7b73989d7ee72be)) by [@victor-falcon](https://github.com/victor-falcon)
* verify email via signed link without requiring login ([#490](https://github.com/whisper-money/whisper-money/issues/490)) ([14b7955](https://github.com/whisper-money/whisper-money/commit/14b79557d79dd1c5c5876b6c6b1a480baedd536e)) by [@victor-falcon](https://github.com/victor-falcon)


### Features

* add catch-all budgets ([#527](https://github.com/whisper-money/whisper-money/issues/527)) ([dbec1c4](https://github.com/whisper-money/whisper-money/commit/dbec1c4c134a257b95c3c1402590a6727026a4d4)) by [@tonigruni](https://github.com/tonigruni)
* **ai:** add weekly AI-suggestions cohort report ([#530](https://github.com/whisper-money/whisper-money/issues/530)) ([906e3cc](https://github.com/whisper-money/whisper-money/commit/906e3cc2b466428e7efa4c4c66cb56a068475451)) by [@victor-falcon](https://github.com/victor-falcon)
* **ai:** auto-categorize transactions with AI (behind flag) ([#535](https://github.com/whisper-money/whisper-money/issues/535)) ([8013a0b](https://github.com/whisper-money/whisper-money/commit/8013a0b6f2d53c6df64bcf6019592dd1f8e477d1)) by [@victor-falcon](https://github.com/victor-falcon)
* **ai:** defer per-transaction categorization until onboarding completes ([#536](https://github.com/whisper-money/whisper-money/issues/536)) ([e065d4a](https://github.com/whisper-money/whisper-money/commit/e065d4ab65f603f5396cafcf4634e544c2eead2f)) by [@victor-falcon](https://github.com/victor-falcon)
* **ai:** share AI sparkle icon and mark AI-generated rules ([#538](https://github.com/whisper-money/whisper-money/issues/538)) ([7dde67c](https://github.com/whisper-money/whisper-money/commit/7dde67c606fa0f8603f7603c0a6769df6755028c)) by [@victor-falcon](https://github.com/victor-falcon)
* **ai:** suggest automation rules during onboarding ([#523](https://github.com/whisper-money/whisper-money/issues/523)) ([8056ede](https://github.com/whisper-money/whisper-money/commit/8056ede63644a4fac2afa88fcc9d89d7fb3bcea1)) by [@victor-falcon](https://github.com/victor-falcon)
* **analysis:** break down spending by sub-category ([#508](https://github.com/whisper-money/whisper-money/issues/508)) ([c944a2c](https://github.com/whisper-money/whisper-money/commit/c944a2c37ecfab3c483a6c0ffa3596bdd8b73f01)) by [@victor-falcon](https://github.com/victor-falcon)
* **analysis:** per-category 12-month spending drawer ([#519](https://github.com/whisper-money/whisper-money/issues/519)) ([9c3c4d5](https://github.com/whisper-money/whisper-money/commit/9c3c4d573ee8a04155d56da39bc213b052a138a4)) by [@victor-falcon](https://github.com/victor-falcon)
* **analysis:** project-aware transaction analysis ([#513](https://github.com/whisper-money/whisper-money/issues/513)) ([7cc49a5](https://github.com/whisper-money/whisper-money/commit/7cc49a53689d95855ca254a4df8ebd6fbad5720c)) by [@victor-falcon](https://github.com/victor-falcon)
* **analysis:** shared bar-list breakdowns in transaction drawer ([#517](https://github.com/whisper-money/whisper-money/issues/517)) ([bcd025f](https://github.com/whisper-money/whisper-money/commit/bcd025f1b16490baf871718b24db71997895e07e)) by [@victor-falcon](https://github.com/victor-falcon)
* **auth:** show/hide toggle on password fields ([#499](https://github.com/whisper-money/whisper-money/issues/499)) ([58254fc](https://github.com/whisper-money/whisper-money/commit/58254fcedeb76ff99b88f5b5fafe00f807510f91)) by [@victor-falcon](https://github.com/victor-falcon)
* **banking:** add command to disconnect connections by id ([#497](https://github.com/whisper-money/whisper-money/issues/497)) ([9192947](https://github.com/whisper-money/whisper-money/commit/91929477ab94cd721bc8b6c3a7c9a28dcdfb31cf)) by [@victor-falcon](https://github.com/victor-falcon)
* **cashflow:** enlarge and color-code net cashflow and saved cards ([#505](https://github.com/whisper-money/whisper-money/issues/505)) ([346e21a](https://github.com/whisper-money/whisper-money/commit/346e21ad4eec3b9dd7369abd6503b66fd7262c92)) by [@victor-falcon](https://github.com/victor-falcon)
* **console:** add agent:db command for querying local and prod DB ([#522](https://github.com/whisper-money/whisper-money/issues/522)) ([d2a4412](https://github.com/whisper-money/whisper-money/commit/d2a44121189624ab5e5c8e1ac1baac4ffd17ec21)) by [@victor-falcon](https://github.com/victor-falcon)
* **currencies:** add Colombian and Dominican peso ([#471](https://github.com/whisper-money/whisper-money/issues/471)) ([e5b4933](https://github.com/whisper-money/whisper-money/commit/e5b493329a6b5b8e6fdf04f873e591546c42441d)) by [@victor-falcon](https://github.com/victor-falcon)
* **currency:** add NZD (New Zealand Dollar) ([#504](https://github.com/whisper-money/whisper-money/issues/504)) ([899ea6a](https://github.com/whisper-money/whisper-money/commit/899ea6a939787e567a41566aeb6fbeee6885600d)) by [@victor-falcon](https://github.com/victor-falcon)
* enable category tree for all users, remove flag ([#488](https://github.com/whisper-money/whisper-money/issues/488)) ([f8cc881](https://github.com/whisper-money/whisper-money/commit/f8cc8816a898514a558667a2938f6255363b2ed7)) by [@victor-falcon](https://github.com/victor-falcon)
* expand parent categories inline in breakdowns ([#486](https://github.com/whisper-money/whisper-money/issues/486)) ([53d0518](https://github.com/whisper-money/whisper-money/commit/53d051800bb1e676563bbc1038e607efc186bebd)) by [@victor-falcon](https://github.com/victor-falcon)
* expand Sankey subcategories inline ([#485](https://github.com/whisper-money/whisper-money/issues/485)) ([679c8d7](https://github.com/whisper-money/whisper-money/commit/679c8d7bff3b69a5b70c55ae90fd1d8236af99ee)) by [@victor-falcon](https://github.com/victor-falcon)
* **importer:** support YYYYMMDD date format ([#470](https://github.com/whisper-money/whisper-money/issues/470)) ([f9d1303](https://github.com/whisper-money/whisper-money/commit/f9d1303d986aa2cee9ee6dbf92d6a3fb708f8f05)) by [@victor-falcon](https://github.com/victor-falcon)
* **landing:** add go now button to redirect modal ([#506](https://github.com/whisper-money/whisper-money/issues/506)) ([c53c40c](https://github.com/whisper-money/whisper-money/commit/c53c40cf0b54a94c163f28ebd504dd720ff03339)) by [@victor-falcon](https://github.com/victor-falcon)
* **landing:** real testimonials with gravatar/facehash avatars ([#493](https://github.com/whisper-money/whisper-money/issues/493)) ([300188f](https://github.com/whisper-money/whisper-money/commit/300188f135a598a4ae2a5bb6f119af1d13cae7eb)) by [@victor-falcon](https://github.com/victor-falcon)
* **open-banking:** finalize bank connection without a session via state token ([#498](https://github.com/whisper-money/whisper-money/issues/498)) ([a7dde5f](https://github.com/whisper-money/whisper-money/commit/a7dde5fbc5e1a09d55d64ff101e5ea4061335fd0)) by [@victor-falcon](https://github.com/victor-falcon)
* optionally update manual account balance on transaction delete ([#491](https://github.com/whisper-money/whisper-money/issues/491)) ([45e25e0](https://github.com/whisper-money/whisper-money/commit/45e25e018de377622197ae0c3c39c9cab6053220)) by [@victor-falcon](https://github.com/victor-falcon)
* parent/child category tree ([#474](https://github.com/whisper-money/whisper-money/issues/474)) ([1cc1056](https://github.com/whisper-money/whisper-money/commit/1cc10566a36562fa37ac7ecb9592da03284f5f51)) by [@victor-falcon](https://github.com/victor-falcon)
* **settings:** let users disable bank transactions email ([#472](https://github.com/whisper-money/whisper-money/issues/472)) ([e178f1b](https://github.com/whisper-money/whisper-money/commit/e178f1b1bdb57a778ae2124e651078d1904f71c4)) by [@victor-falcon](https://github.com/victor-falcon)
* single-open Sankey expand, fit overflowing children ([#487](https://github.com/whisper-money/whisper-money/issues/487)) ([721cbef](https://github.com/whisper-money/whisper-money/commit/721cbef02464acb40ab1428c769b65a042b4d96a)) by [@victor-falcon](https://github.com/victor-falcon)
* **transactions:** filtered analysis dashboard ([#507](https://github.com/whisper-money/whisper-money/issues/507)) ([8375fd4](https://github.com/whisper-money/whisper-money/commit/8375fd490ecc473bbcb7450830ba5b55e4b6bb26)) by [@victor-falcon](https://github.com/victor-falcon)
* **transactions:** save and reuse transaction filters ([#496](https://github.com/whisper-money/whisper-money/issues/496)) ([8df44c2](https://github.com/whisper-money/whisper-money/commit/8df44c2ef492abe4a9f5d385c9eb5ca0835c7675)) by [@victor-falcon](https://github.com/victor-falcon)
* **users:** track last login and last active timestamps ([#516](https://github.com/whisper-money/whisper-money/issues/516)) ([fcf2d3d](https://github.com/whisper-money/whisper-money/commit/fcf2d3d1ad5f4f85542bd92f7bdaf23ec26c8c50)) by [@victor-falcon](https://github.com/victor-falcon)
* **welcome:** add Albert G. testimonial ([#529](https://github.com/whisper-money/whisper-money/issues/529)) ([0f48ffb](https://github.com/whisper-money/whisper-money/commit/0f48ffbbeffe12321d434c9e4714d09eb2ce9b02)) by [@victor-falcon](https://github.com/victor-falcon)

## [0.2.4](https://github.com/whisper-money/whisper-money/compare/v0.2.3...v0.2.4) (2026-06-01)


### Bug Fixes

* **accounts:** sync currency from first account ([#430](https://github.com/whisper-money/whisper-money/issues/430)) ([0d4c683](https://github.com/whisper-money/whisper-money/commit/0d4c68361afc114615323418e2cfcf380e5ac13f))
* **accounts:** translate update button in edit account modal ([#455](https://github.com/whisper-money/whisper-money/issues/455)) ([65175e1](https://github.com/whisper-money/whisper-money/commit/65175e184a900404b12a5acccabfa0d700ba57ea))
* **automation:** avoid rule preview n+1 ([#431](https://github.com/whisper-money/whisper-money/issues/431)) ([fd67cf7](https://github.com/whisper-money/whisper-money/commit/fd67cf7c72148ce6e3afa97a2cb35893f3457e4b))
* **automation:** avoid skipping rule matches ([#433](https://github.com/whisper-money/whisper-money/issues/433)) ([9772cfc](https://github.com/whisper-money/whisper-money/commit/9772cfc37c19d521ab8b1cd64a5de467ae4ae5ce))
* **banking:** handle balance-fetch timeouts and silence handled retries ([#450](https://github.com/whisper-money/whisper-money/issues/450)) ([64b78e3](https://github.com/whisper-money/whisper-money/commit/64b78e36801a34321ee2fc12ef41a2938d13c572))
* batch automation rule application ([#435](https://github.com/whisper-money/whisper-money/issues/435)) ([606093d](https://github.com/whisper-money/whisper-money/commit/606093d311f307abe76e2c5ac10c7afc6f927578))
* **budgets:** explain locked edit fields ([#437](https://github.com/whisper-money/whisper-money/issues/437)) ([235911b](https://github.com/whisper-money/whisper-money/commit/235911b960c80ba1f18dc059ced6af7b43b225da))
* **cashflow:** clarify period comparisons ([#436](https://github.com/whisper-money/whisper-money/issues/436)) ([0250fdc](https://github.com/whisper-money/whisper-money/commit/0250fdc268ab27e4058a4a5bf6efa4b699133944))
* **cashflow:** defer period label translation ([#427](https://github.com/whisper-money/whisper-money/issues/427)) ([1278a2b](https://github.com/whisper-money/whisper-money/commit/1278a2b972bf85649d0f2390277765b8a3f0bc8b))
* **cashflow:** stack mobile header controls ([#426](https://github.com/whisper-money/whisper-money/issues/426)) ([949ca11](https://github.com/whisper-money/whisper-money/commit/949ca110fa2d250e316af45d5ca42a932b919343))
* **categories:** allow recreate after delete ([#444](https://github.com/whisper-money/whisper-money/issues/444)) ([2fa822e](https://github.com/whisper-money/whisper-money/commit/2fa822e6d960f73b4d168e012a1a003a86415f85))
* **categories:** expose cashflow setting on create ([#448](https://github.com/whisper-money/whisper-money/issues/448)) ([5119528](https://github.com/whisper-money/whisper-money/commit/5119528149a1ea1686e70d081418a10ec61edd68))
* **chart:** mask stacked bar edges ([#439](https://github.com/whisper-money/whisper-money/issues/439)) ([d5d262e](https://github.com/whisper-money/whisper-money/commit/d5d262e9fd5720b21bbe1c44500ef1034290cf9a))
* **currency:** degrade gracefully when rates return 404 ([#449](https://github.com/whisper-money/whisper-money/issues/449)) ([bef657c](https://github.com/whisper-money/whisper-money/commit/bef657c2ed8a7ed7be1b7c7d232dc037d76ee39a))
* filter Safari cashback extension errors ([#447](https://github.com/whisper-money/whisper-money/issues/447)) ([0b94067](https://github.com/whisper-money/whisper-money/commit/0b9406714e158244b64cb449e6b19320305ee6b9))
* **import:** correct balance for same-day, zero and negative values ([#456](https://github.com/whisper-money/whisper-money/issues/456)) ([144d919](https://github.com/whisper-money/whisper-money/commit/144d919c0b23d8231b1095e148d4ca4a58f0e955))
* **logging:** keep laravel.log writable across container UIDs ([#451](https://github.com/whisper-money/whisper-money/issues/451)) ([741dc49](https://github.com/whisper-money/whisper-money/commit/741dc49d5371b3aa867d45c78974fe0049d6b346))
* move community link to user menu ([#442](https://github.com/whisper-money/whisper-money/issues/442)) ([4f46ae3](https://github.com/whisper-money/whisper-money/commit/4f46ae3e2d9e365a1bb45355fe2f2c310cab2bb8))
* net category refunds in cashflow ([#441](https://github.com/whisper-money/whisper-money/issues/441)) ([6caadad](https://github.com/whisper-money/whisper-money/commit/6caadad1dbc267ea5d2bb58f2ce92200146349bf))
* **register:** don't block signup on unrecognized browser timezone ([#462](https://github.com/whisper-money/whisper-money/issues/462)) ([96ee311](https://github.com/whisper-money/whisper-money/commit/96ee311299b0fb6d8234fd17cab44caa3c886488))


### Features

* **accounts:** add transaction action ([#438](https://github.com/whisper-money/whisper-money/issues/438)) ([534a147](https://github.com/whisper-money/whisper-money/commit/534a14790e777f3bd3d993fe6083fef40a9727ab))
* **accounts:** show 50 transactions per page and link to full list ([#459](https://github.com/whisper-money/whisper-money/issues/459)) ([85ea3cc](https://github.com/whisper-money/whisper-money/commit/85ea3cc71416d446fdc8858fbd8a562a02334182))
* add BRL currency support ([#453](https://github.com/whisper-money/whisper-money/issues/453)) ([4dec0ab](https://github.com/whisper-money/whisper-money/commit/4dec0ab7ca14e3100ee2b584a22b39f8cdf8e110))
* add Discord admin feed for daily stats and Stripe events ([#458](https://github.com/whisper-money/whisper-money/issues/458)) ([0b528b7](https://github.com/whisper-money/whisper-money/commit/0b528b79025314294db53a61a07231199b2b864c)), closes [#457](https://github.com/whisper-money/whisper-money/issues/457)
* add PKR currency support ([#443](https://github.com/whisper-money/whisper-money/issues/443)) ([cfa61fd](https://github.com/whisper-money/whisper-money/commit/cfa61fd23cc9b7888755684d24ede3f6a35863cb))
* add Stripe subscription stats command ([#457](https://github.com/whisper-money/whisper-money/issues/457)) ([670a0a6](https://github.com/whisper-money/whisper-money/commit/670a0a65c73d19da091f95ea9a381d7dcab1b240))
* **budgets:** track multiple categories and labels per budget ([#466](https://github.com/whisper-money/whisper-money/issues/466)) ([71dd6e2](https://github.com/whisper-money/whisper-money/commit/71dd6e2b7fcf44d889641842a13e19091a47d186))
* **cashflow:** add savings and period views ([#424](https://github.com/whisper-money/whisper-money/issues/424)) ([ed737db](https://github.com/whisper-money/whisper-money/commit/ed737db7b28442ea3674290eb7eb3f15744dd5b2))
* **cashflow:** rework summary cards into net + saved/invested ([#465](https://github.com/whisper-money/whisper-money/issues/465)) ([5ce439f](https://github.com/whisper-money/whisper-money/commit/5ce439f463bfdac7ebb3aa5c0396d098999fdad6))
* **categorize:** show debtor and creditor names ([#454](https://github.com/whisper-money/whisper-money/issues/454)) ([05ee8dc](https://github.com/whisper-money/whisper-money/commit/05ee8dc44258e7c9e6dcb6e77afedeb92f3329a2))
* **currency:** add Saudi Riyal (SAR) ([#461](https://github.com/whisper-money/whisper-money/issues/461)) ([a71626a](https://github.com/whisper-money/whisper-money/commit/a71626a350a568fc0591847aba6b0faac0d484fb))
* **discord:** enrich Stripe event messages and dedupe deliveries ([#460](https://github.com/whisper-money/whisper-money/issues/460)) ([f9bf0ea](https://github.com/whisper-money/whisper-money/commit/f9bf0ea5fff32f26af8d05dc5645a302524b3a95))
* **landing:** redirect signed-in users ([#429](https://github.com/whisper-money/whisper-money/issues/429)) ([4f42de7](https://github.com/whisper-money/whisper-money/commit/4f42de74a1beda4b7032a6e2fe6d9d1eec4edd4c))
* **leads:** add user lead re-invite campaign ([#432](https://github.com/whisper-money/whisper-money/issues/432)) ([7b03d7c](https://github.com/whisper-money/whisper-money/commit/7b03d7cf230ef0cea3fe4ce6ea2215e3348910f9))
* **leads:** schedule invitation emails ([#434](https://github.com/whisper-money/whisper-money/issues/434)) ([d5d22b9](https://github.com/whisper-money/whisper-money/commit/d5d22b9d5724cfb7363ead047946ff587772d9ea))
* **posthog:** route analytics through reverse proxy ([#463](https://github.com/whisper-money/whisper-money/issues/463)) ([448bb2e](https://github.com/whisper-money/whisper-money/commit/448bb2e64af8ebb8b2a28700d793b97fba75bf6d))
* **transactions:** add counterparty fields ([#440](https://github.com/whisper-money/whisper-money/issues/440)) ([10da06e](https://github.com/whisper-money/whisper-money/commit/10da06ed849c019fcd041d5889d5864a6c26844f))

## [0.2.3](https://github.com/whisper-money/whisper-money/compare/v0.2.2...v0.2.3) (2026-05-25)


### Bug Fixes

* allow managing canceled connections ([#417](https://github.com/whisper-money/whisper-money/issues/417)) ([eaba315](https://github.com/whisper-money/whisper-money/commit/eaba3151967a942044cffbd33c0dcee8d00cb457))
* build arm64 images on native runners ([#419](https://github.com/whisper-money/whisper-money/issues/419)) ([364c72d](https://github.com/whisper-money/whisper-money/commit/364c72db72812e337a08813019bfd8d6c84ce1a3))
* preview categorizer rule application ([#421](https://github.com/whisper-money/whisper-money/issues/421)) ([faf046b](https://github.com/whisper-money/whisper-money/commit/faf046b3c4213417190504cd0b7491012e7fcffd))
* publish arm64 docker images asynchronously ([#418](https://github.com/whisper-money/whisper-money/issues/418)) ([4411601](https://github.com/whisper-money/whisper-money/commit/44116010dee35eb93b6826e4c955d0c662aa5927)), closes [#412](https://github.com/whisper-money/whisper-money/issues/412)


### Features

* keep past due subscriptions active ([#416](https://github.com/whisper-money/whisper-money/issues/416)) ([88faa5b](https://github.com/whisper-money/whisper-money/commit/88faa5beb60c8b9948ff11284609b21fbfebee30))
* **mail:** use AWS SES for email delivery ([#422](https://github.com/whisper-money/whisper-money/issues/422)) ([d8bb78e](https://github.com/whisper-money/whisper-money/commit/d8bb78e5e096938292d0a971637d077d76cf61a8))

## [0.2.2](https://github.com/whisper-money/whisper-money/compare/v0.2.0...v0.2.2) (2026-05-22)


### Bug Fixes

* **banking:** dedup EnableBanking transactions by deterministic fingerprint ([#390](https://github.com/whisper-money/whisper-money/issues/390)) ([d9204bb](https://github.com/whisper-money/whisper-money/commit/d9204bb3d611c382493e45691d9ec963a73bf454))
* **banking:** treat Indexa Capital performance 404 as empty data ([#386](https://github.com/whisper-money/whisper-money/issues/386)) ([06e7eed](https://github.com/whisper-money/whisper-money/commit/06e7eed4e20796accf0555659baeffb481e925bd))
* **budget:** show today marker ([#411](https://github.com/whisper-money/whisper-money/issues/411)) ([933dfde](https://github.com/whisper-money/whisper-money/commit/933dfdeb1b32ffba04850b973d9c3d4681262053))
* **connections:** show expired reconnect ([#407](https://github.com/whisper-money/whisper-money/issues/407)) ([d2e00f1](https://github.com/whisper-money/whisper-money/commit/d2e00f14e5302ccd40f620733479fc7a7b410699))
* keep lead invite command aliases ([#406](https://github.com/whisper-money/whisper-money/issues/406)) ([11f989d](https://github.com/whisper-money/whisper-money/commit/11f989d03af290f9ee32bc6e46fad327dd2c1e03))
* **notifications:** skip mail dispatch when recipient email is invalid ([#387](https://github.com/whisper-money/whisper-money/issues/387)) ([d140b4f](https://github.com/whisper-money/whisper-money/commit/d140b4fd4cd5402b464c203d619c231db9672ef7))
* **sentry:** ignore postMessage clone noise ([#373](https://github.com/whisper-money/whisper-money/issues/373)) ([6335287](https://github.com/whisper-money/whisper-money/commit/63352877654bc880e09e36d0a3efada527961d82))


### Features

* Coinbase banking integration ([#388](https://github.com/whisper-money/whisper-money/issues/388)) ([e71a743](https://github.com/whisper-money/whisper-money/commit/e71a743a0a6e3a1a6bde5c1bc29df94555f74bd1))
* **import:** calculate balances from transactions ([#403](https://github.com/whisper-money/whisper-money/issues/403)) ([66ff427](https://github.com/whisper-money/whisper-money/commit/66ff427481dfe0e00cf632e3f0f1caef33238636))

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
