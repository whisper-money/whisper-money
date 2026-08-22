<?php

namespace App\Support\Marketing;

/**
 * Copy for the public marketing pages, in every language they are published in.
 *
 * It lives in PHP rather than in the frontend because three surfaces read the
 * same words: the Inertia pages, the Markdown representations agents fetch, and
 * llms.txt. A second copy in TypeScript would drift from this one.
 *
 * It is not routed through the translation files either. These are long pages of
 * marketing prose per market, and lang/*.json is keyed by English source string,
 * which would make every edit to an English sentence silently drop the Spanish
 * one. Keeping both languages side by side makes a missing translation visible.
 *
 * Every claim about a competitor is a fact published on that competitor's own
 * pages and was verified on 2026-08-18. Re-check the figure before editing a
 * page and update the date quoted inside the row.
 */
final class MarketingContent
{
    /**
     * Languages the comparison pages are published in. French is deliberately
     * absent: the product UI has it, but nobody has written these pages in it,
     * and a half-translated landing is worse for search than none.
     */
    public const LOCALES = ['en', 'es'];

    /**
     * First path segment per language, so each language has its own URL.
     */
    public const BASE_PATHS = ['en' => 'compare', 'es' => 'comparativa'];

    /**
     * The integrations page path per language. One page per language, not one
     * page per bank: a few thousand near-identical pages is a doorway pattern.
     */
    public const INTEGRATION_PATHS = ['en' => 'integrations', 'es' => 'integraciones'];

    /**
     * Countries the bank connect flow offers, with the name to print in each
     * language. The codes mirror CONNECT_COUNTRIES in the frontend connect
     * flow; a test fails if the two lists stop agreeing.
     */
    public const COUNTRIES = [
        'ES' => ['en' => 'Spain', 'es' => 'España'],
        'DE' => ['en' => 'Germany', 'es' => 'Alemania'],
        'FR' => ['en' => 'France', 'es' => 'Francia'],
        'IT' => ['en' => 'Italy', 'es' => 'Italia'],
        'NL' => ['en' => 'Netherlands', 'es' => 'Países Bajos'],
        'PT' => ['en' => 'Portugal', 'es' => 'Portugal'],
        'BE' => ['en' => 'Belgium', 'es' => 'Bélgica'],
        'AT' => ['en' => 'Austria', 'es' => 'Austria'],
        'FI' => ['en' => 'Finland', 'es' => 'Finlandia'],
        'IE' => ['en' => 'Ireland', 'es' => 'Irlanda'],
        'LT' => ['en' => 'Lithuania', 'es' => 'Lituania'],
        'LV' => ['en' => 'Latvia', 'es' => 'Letonia'],
        'EE' => ['en' => 'Estonia', 'es' => 'Estonia'],
        'SE' => ['en' => 'Sweden', 'es' => 'Suecia'],
        'NO' => ['en' => 'Norway', 'es' => 'Noruega'],
        'DK' => ['en' => 'Denmark', 'es' => 'Dinamarca'],
        'PL' => ['en' => 'Poland', 'es' => 'Polonia'],
        'GB' => ['en' => 'United Kingdom', 'es' => 'Reino Unido'],
    ];

    /**
     * The code for the group that holds what is not tied to one country: the
     * crypto exchanges, Wise and Interactive Brokers.
     */
    public const WORLDWIDE = 'WW';

    /**
     * Name of the worldwide group per language.
     */
    public const WORLDWIDE_NAMES = ['en' => 'Worldwide', 'es' => 'En todo el mundo'];

    /**
     * The apps that connect with a key the user creates, rather than by
     * approving access at a bank. They are listed among the banks, in the
     * country group they belong to, because a visitor looking for Coinbase is
     * asking the same question as one looking for BBVA.
     *
     * The names and logos are the join with the frontend registry: a test
     * asserts both still match CONNECT_PROVIDERS in
     * resources/js/lib/connect-providers, so one cannot change without the
     * other.
     */
    public const API_PROVIDERS = [
        'Indexa Capital' => ['country' => 'ES', 'logo' => '/images/banks/logos/indexa-capital.jpg'],
        'Binance' => ['country' => self::WORLDWIDE, 'logo' => 'https://whisper.money/storage/banks/logos/t1h5rqi19dJTPl6ZadziPjNwm0lrcdTFBRzB3iCy.png'],
        'Bitpanda' => ['country' => self::WORLDWIDE, 'logo' => 'https://whisper.money/storage/banks/logos/7Y6gl0gaFH1mStJMcUQ9VpgzX1kduyumm0dDhGlf.png'],
        'Coinbase' => ['country' => self::WORLDWIDE, 'logo' => 'https://whisper.money/storage/banks/logos/coinbase.png'],
        'Wise' => ['country' => self::WORLDWIDE, 'logo' => '/images/banks/logos/wise.png'],
        'Interactive Brokers' => ['country' => self::WORLDWIDE, 'logo' => '/images/banks/logos/interactive-brokers.png'],
    ];

    /**
     * Section headings shared by every comparison page, so the eight pages do
     * not each carry their own copy of them.
     *
     * @return array<string, string>
     */
    public static function labels(string $locale): array
    {
        return $locale === 'es' ? [
            'dimension' => 'Dimensión',
            'head_to_head' => 'Cara a cara',
            'changes' => 'Lo que cambia al pasar de :rival a Whisper Money',
            'migration' => 'Cómo migrar de :rival a Whisper Money, paso a paso',
            'testimonials' => 'Lo que dicen quienes ya lo usan',
            'closing' => 'Únete a quienes migraron desde :rival',
            'cta' => 'Crear mi cuenta gratis',
            'pricing' => 'Ver todos los precios',
            'back' => 'Volver al inicio',
            'other_language' => 'View this page in English',
        ] : [
            'dimension' => 'Dimension',
            'head_to_head' => 'Head to head',
            'changes' => 'What changes when you move from :rival to Whisper Money',
            'migration' => 'How to migrate from :rival to Whisper Money, step by step',
            'testimonials' => 'What people already using it say',
            'closing' => 'Join the people who moved from :rival',
            'cta' => 'Create my free account',
            'pricing' => 'See all pricing',
            'back' => 'Back to home',
            'other_language' => 'Ver esta página en español',
        ];
    }

    /**
     * Every comparison page, keyed by a language-independent page key.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function comparisonPages(): array
    {
        return [
            'fintonic' => self::fintonic(),
            'ynab' => self::ynab(),
            'spreadsheets' => self::spreadsheets(),
            'bank-app' => self::bankApp(),
            'wallet' => self::wallet(),
            'monefy' => self::monefy(),
            'margen' => self::margen(),
            'dinerio' => self::dinerio(),
        ];
    }

    /**
     * A short factual summary of the product for agents, assembled from what
     * the code actually supports rather than from the landing page's hero copy.
     *
     * @return array<string, mixed>
     */
    public static function landing(string $locale): array
    {
        return $locale === 'es' ? [
            'title' => 'Whisper Money — finanzas personales privadas',
            'summary' => 'Whisper Money reúne todas tus cuentas bancarias, brokers, cripto, inmuebles y préstamos en un solo sitio, calcula tu patrimonio neto y te deja presupuestar por categoría. Es una aplicación web instalable en el móvil, el código es público y no vendemos ni compartimos tus datos con terceros.',
            'sections' => [
                'Qué hace' => [
                    'Agrega cuentas de bancos españoles y europeos por PSD2, la normativa europea de banca abierta. La autorización se firma en la web de tu propio banco: no vemos ni almacenamos tus credenciales.',
                    'Integra brokers y exchanges con claves de API que generas tú: Indexa Capital, Interactive Brokers, Wise, Binance, Bitpanda y Coinbase.',
                    'Importa extractos en CSV, XLS y XLSX con detección automática de columnas (incluidas las cabeceras en español), cuatro formatos de fecha, detección de duplicados y mapeo guardado por cuenta.',
                    'Categoriza con reglas de automatización propias y, de forma opcional y con consentimiento explícito, con IA que aprende de tus correcciones.',
                    'Presupuestos por categoría con periodo semanal, quincenal, mensual o anual, arrastre del sobrante y avisos.',
                    'Patrimonio neto, flujo de caja mensual y multidivisa con 33 monedas.',
                    'Acceso por MCP para consultar tus finanzas desde un asistente, en el plan de pago.',
                ],
                'Qué no hace' => [
                    'No intermedia préstamos, seguros ni ningún producto financiero, y no muestra publicidad.',
                    'No vende ni comparte tus datos con terceros.',
                    'No cifra los datos en reposo: el modelo es almacenamiento en claro en base de datos propia. Lo que se puede comprobar es el código, que es público.',
                    'No tiene un botón de exportación en la interfaz. La vía para sacar los datos de forma programática es el acceso por MCP, en el plan de pago.',
                ],
                'Precio' => [
                    'Hay un plan gratuito permanente y un plan de pago en euros. Las cuentas conectadas, las sugerencias con IA y el acceso por MCP requieren el plan de pago. La tarifa vigente está publicada en la página de precios.',
                ],
                'Plataformas e idiomas' => [
                    'Aplicación web en cualquier navegador, instalable como app en iPhone y Android. Interfaz en español, inglés y francés.',
                ],
            ],
        ] : [
            'title' => 'Whisper Money — private personal finance',
            'summary' => 'Whisper Money brings your bank accounts, brokers, crypto, property and loans together in one place, works out your net worth and lets you budget by category. It is a web app you can install on your phone, the code is public, and we do not sell or share your data with third parties.',
            'sections' => [
                'What it does' => [
                    'Aggregates Spanish and European bank accounts over PSD2, the European open banking regulation. You sign the authorisation on your own bank\'s website: we never see or store your credentials.',
                    'Integrates brokers and exchanges through API keys you generate yourself: Indexa Capital, Interactive Brokers, Wise, Binance, Bitpanda and Coinbase.',
                    'Imports statements in CSV, XLS and XLSX with automatic column detection (Spanish headers included), four date formats, duplicate detection and per-account saved mapping.',
                    'Categorises with your own automation rules and, optionally and only with explicit consent, with AI that learns from your corrections.',
                    'Category budgets on a weekly, biweekly, monthly or yearly period, with rollover and alerts.',
                    'Net worth, monthly cash flow and multi-currency support across 33 currencies.',
                    'MCP access, on the paid plan, so an assistant can answer questions about your finances.',
                ],
                'What it does not do' => [
                    'It does not intermediate loans, insurance or any financial product, and it shows no ads.',
                    'It does not sell or share your data with third parties.',
                    'It does not encrypt data at rest: the model is plaintext storage in our own database. What you can verify instead is the code, which is public.',
                    'It has no export button in the interface. The way to pull your data out programmatically is MCP access, on the paid plan.',
                ],
                'Pricing' => [
                    'There is a permanent free plan and a paid plan billed in euros. Connected accounts, AI suggestions and MCP access need the paid plan. The current rate is published on the pricing page.',
                ],
                'Platforms and languages' => [
                    'A web app in any browser, installable as an app on iPhone and Android. Interface in English, Spanish and French.',
                ],
            ],
        ];
    }

    /**
     * The integrations page: what a visitor can connect, before they register.
     *
     * Written for someone who has never heard of PSD2 or open banking, because
     * that is nearly everyone. It says what they get, not how it is plumbed:
     * the regulation's name tells a reader nothing, and "you approve it on your
     * bank's own site, so we never see your login" tells them everything.
     *
     * The :count and :countries placeholders are filled from the committed
     * catalogue so the figures cannot go stale against the list right below
     * them. The filter's own counter uses :matches instead, which the browser
     * fills per keystroke.
     *
     * @return array<string, mixed>
     */
    public static function integrations(string $locale): array
    {
        return $locale === 'es' ? [
            'title' => 'Bancos y apps compatibles',
            'description' => 'Comprueba antes de registrarte si podemos conectar tu banco: :count entidades y apps de :countries países, más Binance, Bitpanda, Coinbase, Wise e Interactive Brokers. Busca la tuya en la lista.',
            'heading' => 'Qué bancos y apps puedes conectar',
            'intro' => 'Conectas una cuenta una vez y sus movimientos y saldos siguen llegando solos: tus cuentas, tus inversiones y tu cripto en un mismo sitio, sin apuntar nada a mano. El permiso lo das en la web de tu propio banco, así que tus claves nunca pasan por nosotros. Busca abajo lo que quieras seguir.',
            'banks_title' => 'Todo lo que puedes conectar',
            'banks_intro' => ':count entidades y apps de :countries países. Busca la tuya: si está en esta lista, puedes conectarla.',
            'banks_note' => 'El permiso se firma en la web de tu banco, así que nosotros no vemos ni guardamos tus claves. Las marcadas con una llave se conectan con un código que creas tú en su propia web y que puedes limitar a solo lectura. Las cuentas conectadas están en el plan de pago; la tarifa vigente está en la página de precios.',
            'search_label' => 'Filtrar la lista',
            'search_placeholder' => 'Busca tu banco o tu app',
            'matches' => ':matches coincidencias',
            'matches_one' => '1 coincidencia',
            'empty_title' => '¿No está en la lista?',
            'empty_body' => 'Entonces todavía no se conecta sola, pero no te quedas fuera: puedes traerla con su extracto, tal y como se explica justo debajo.',
            'importer_title' => 'Y si tu banco no está, su extracto sí entra',
            'importer_body' => 'Descargas el extracto de tu banco y lo sueltas aquí, tal cual, en CSV, XLS o XLSX. No tienes que ordenar ni renombrar nada: reconoce las columnas por su cuenta, tanto si vienen en español como en inglés, entiende las fechas escritas de cuatro formas distintas y recuerda cómo era el fichero para la próxima vez. Si repites un mes que ya habías subido, te avisa de lo repetido y lo deja sin marcar, así que no acabas con nada por duplicado.',
            'notes_title' => 'Lo que conviene saber antes de registrarte',
            'notes' => [
                'Las cuentas conectadas, la categorización automática y el acceso desde un asistente están en el plan de pago. Con el plan gratuito llevas cuentas a mano e importas extractos.',
                'Un permiso bancario caduca a los pocos meses y se renueva en un par de clics. Es la norma de los bancos, no nuestra: pedimos 90 días, y cada entidad concede lo que quiere.',
                'Cuánto histórico te entrega tu banco, y cada cuánto te pide renovar, lo decide él y no nosotros. Hay entidades que dan varios años y otras que dan unas semanas.',
                'Todavía no hay un botón de exportar en la aplicación. Para sacar tus datos de forma automática está el acceso desde un asistente, en el plan de pago.',
                'Tus datos se guardan sin cifrar en nuestra base de datos. Lo que sí puedes comprobar es el código, que es público en GitHub.',
            ],
            'closing_title' => 'Cuando quieras',
            'closing_body' => 'El plan gratuito no pide tarjeta. Si tu banco está en la lista, tenerlo conectado es cuestión de un par de minutos.',
        ] : [
            'title' => 'Supported Banks and Apps',
            'description' => 'Check before you sign up whether we can connect your bank: :count banks and apps across :countries countries, plus Binance, Bitpanda, Coinbase, Wise and Interactive Brokers. Search the list for yours.',
            'heading' => 'Which banks and apps you can connect',
            'intro' => 'Connect an account once and its balances and transactions keep arriving on their own: your accounts, your investments and your crypto in one place, with nothing typed in by hand. You give permission on your own bank\'s website, so your login details never pass through us. Search below for whatever you want to track.',
            'banks_title' => 'Everything you can connect',
            'banks_intro' => ':count banks and apps across :countries countries. Search for yours: if it is on this list, you can connect it.',
            'banks_note' => 'You give permission on your bank\'s own website, so we never see or store your login details. The ones marked with a key connect with a code you create on their own site, which you can limit to reading only. Connected accounts are on the paid plan; the current rate is on the pricing page.',
            'search_label' => 'Filter the list',
            'search_placeholder' => 'Search for your bank or app',
            'matches' => ':matches matches',
            'matches_one' => '1 match',
            'empty_title' => 'Not on the list?',
            'empty_body' => 'Then it does not connect on its own yet, but you are not stuck: you can bring it in from its statement, exactly as described below.',
            'importer_title' => 'And if your bank is not there, its statement still is',
            'importer_body' => 'Download the statement from your bank and drop it in as it comes, in CSV, XLS or XLSX. You do not have to tidy it up or rename anything: it works out the columns on its own, whether they arrive in English or in Spanish, reads dates written four different ways, and remembers the shape of the file for next time. Re-upload a month you already had and it points out what is repeated and leaves it unticked, so you never end up with anything twice.',
            'notes_title' => 'Worth knowing before you sign up',
            'notes' => [
                'Connected accounts, automatic categorising and access from an assistant are on the paid plan. On the free plan you keep accounts by hand and import statements.',
                'A bank permission expires after a few months and takes a couple of clicks to renew. That is the banks\' rule rather than ours: we ask for 90 days, and each one grants what it likes.',
                'How much history your bank hands over, and how often it asks you to renew, is its decision and not ours. Some give several years, others give a few weeks.',
                'There is no export button in the app yet. To pull your data out automatically there is access from an assistant, on the paid plan.',
                'Your data is stored unencrypted in our database. What you can verify instead is the code, which is public on GitHub.',
            ],
            'closing_title' => 'Ready when you are',
            'closing_body' => 'The free plan asks for no card. If your bank is on the list, having it connected is a couple of minutes\' work.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function fintonic(): array
    {
        return [
            'testimonials' => [
                [
                    'name' => 'Marcus Oliveira',
                    'gravatar' => '3c4342baddf0beb8b0bd9fe89168e282',
                    'text' => [
                        'en' => 'Thank you for developing Whisper Money. The focus on privacy and centralizing finances is an excellent proposition.',
                        'es' => 'Gracias por desarrollar Whisper Money. El enfoque en la privacidad y en centralizar las finanzas es una propuesta excelente.',
                    ],
                ],
                [
                    'name' => 'Marta Bordiu',
                    'gravatar' => '8329bd04eb4272db2e94f7c849ca7776',
                    'text' => [
                        'en' => "I'd been holding back on finance apps because I worried about my data — this is the first one I trusted enough to go premium. I love that I can put everything in one place, investments included.",
                        'es' => 'Llevaba tiempo sin dar el paso con las apps de finanzas porque me preocupaban mis datos; esta es la primera en la que confié lo suficiente como para pasarme a premium. Me encanta poder tenerlo todo en un solo sitio, inversiones incluidas.',
                    ],
                ],
            ],
            'en' => [
                'slug' => 'fintonic-alternative',
                'rival' => 'Fintonic',
                'title' => 'Fintonic Alternative (2026) | Whisper Money',
                'description' => 'Fintonic is free because it earns by intermediating loans and insurance. Whisper Money is paid for by a subscription, aggregates your banks over PSD2 and adds investments and crypto. Comparison and migration guide.',
                'heading' => 'Fintonic alternative',
                'intro' => 'Fintonic is the app that got Spain used to checking its accounts from a phone. It is free, it sits on the Bank of Spain register and it aggregates banks over PSD2. What is worth looking at before you settle in is where its money comes from: its own site explains that it intermediates loans with more than forty lenders, along with insurance and interest-bearing accounts.',
                'rows' => [
                    [
                        'dimension' => 'Price and business model',
                        'rival' => 'Free. Revenue comes from intermediating loans, insurance and interest-bearing accounts: "we intermediate for you with more than 40 entities", says its site (checked 18 August 2026).',
                        'whisper' => 'A free plan and a paid subscription. We intermediate no financial product, so nobody pays us for what shows up on your screen.',
                    ],
                    [
                        'dimension' => 'What is inside the app',
                        'rival' => 'Alongside the spending analysis, offers for loans, insurance and interest-bearing accounts.',
                        'whisper' => 'Only your accounts and your numbers. No third-party offers.',
                    ],
                    [
                        'dimension' => 'What you can aggregate',
                        'rival' => 'Accounts and cards at Spanish banks.',
                        'whisper' => 'Banks over PSD2, plus brokers (Indexa Capital, Interactive Brokers), crypto (Binance, Bitpanda, Coinbase) and Wise, all inside the same net worth.',
                    ],
                    [
                        'dimension' => 'What you can verify',
                        'rival' => 'Closed source: its privacy policy is all you get.',
                        'whisper' => 'The code is public on GitHub, so you can read what the app does with your data instead of taking a promise on trust.',
                    ],
                ],
                'narrative_title' => 'The small print: a free app has to charge somebody',
                'narrative' => [
                    'Fintonic being free is not a trick. It is a business model decision, and it is stated on its own site. It reads your profile, matches it against the offers of more than forty lenders, and gets paid by the lender when the loan or the policy goes through. It costs you nothing. For the app, your spending history is the raw material it uses to work out which product fits you.',
                    'That changes what the app is optimising for. One that lives off intermediation has a reason to know how much debt you could take on; one that lives off a subscription only has a reason to keep you using it next month. Whoever pays sets the priority.',
                    'Whisper Money charges a subscription and has a free plan with no advertising. There is no loan comparison engine, no insurance, no interest-bearing accounts, no commission from anyone for what you see. If it stops being useful, you cancel, and that is the whole of our incentive.',
                ],
                'bullets' => [
                    'Bank connections over PSD2 with Spanish and European banks, the same standard Fintonic uses.',
                    'Not one financial product for sale inside the app.',
                    'Consolidated net worth: current accounts, savings, brokers, crypto, property and loans in a single number.',
                    'Direct integrations with Indexa Capital, Interactive Brokers, Wise, Binance, Bitpanda and Coinbase, on top of the banks.',
                    'Category budgets on a weekly, biweekly, monthly or yearly period, with the leftover rolling into the next one.',
                    'Your own automation rules: define once how a merchant gets categorised and it applies to everything that arrives.',
                    'Optional AI categorisation, which only runs if you give explicit consent.',
                    'Statement import in CSV, XLS and XLSX with column mapping, for the accounts that do not connect.',
                    'A web app installable on your phone, in English, Spanish or French, with public code and no data shared with third parties.',
                ],
                'migration_intro' => "Fintonic shows the transactions your bank hands it, so the reliable source for your history is the bank, not the app. Our importer is built for exactly that: you take the bank's file and it works out the columns.",
                'migration_steps' => [
                    [
                        'title' => 'Create your account and connect your banks',
                        'body' => "During sign-up you pick your bank and authorise access over PSD2 on the bank's own website, just as you did with Fintonic. We never see or store your credentials. The first sync pulls whatever history your bank hands over, which varies from one bank to another; if you want more years, carry on to the next step.",
                    ],
                    [
                        'title' => 'Download your history from the bank',
                        'body' => 'Log in to each account\'s online banking and export the transactions over the widest date range it allows, in CSV, XLS or XLSX. Files from Spanish banks almost always carry the columns Fecha, F. Valor, Concepto, Importe and Saldo. You do not need to touch them.',
                    ],
                    [
                        'title' => 'Open the importer and pick the destination account',
                        'body' => 'Under Transactions, open the importer and choose which account those transactions belong to. Then drag the file in or browse for it: it accepts .csv, .xls and .xlsx.',
                    ],
                    [
                        'title' => 'Confirm the column mapping',
                        'body' => 'The importer recognises Spanish headers on its own (Fecha, F. Valor, Concepto, Descripción, Importe, Saldo) and proposes the mapping. You confirm date, description and amount, and optionally balance, creditor and debtor. You can join several columns into the description and pick the date format from DD-MM-YYYY, YYYY-MM-DD, MM-DD-YYYY and YYYYMMDD, with a preview of the first three rows. The mapping is saved for that account, so the next file from the same bank goes through untouched.',
                    ],
                    [
                        'title' => 'Check the duplicates and confirm',
                        'body' => 'Before importing you see the total, how many transactions are selected and how many are duplicates. Duplicates are matched against what is already in the account and come unticked, so you can re-import a month without worrying. On confirm your automation rules run, and if you have turned the AI on it categorises whatever is left.',
                    ],
                ],
                'closing_body' => 'People are already managing here what they used to check in Fintonic: the same accounts, with no loan offers in the way and their investments inside the total. The free plan asks for no card.',
            ],
            'es' => [
                'slug' => 'alternativa-a-fintonic',
                'rival' => 'Fintonic',
                'title' => 'Alternativa a Fintonic (2026) | Whisper Money',
                'description' => 'Fintonic es gratis porque gana dinero intermediando préstamos y seguros. Whisper Money se paga con una suscripción, agrega tus bancos por PSD2 y suma inversiones y cripto. Comparativa y guía de migración.',
                'heading' => 'Alternativa a Fintonic',
                'intro' => 'Fintonic fue la app que acostumbró a España a mirar sus cuentas desde el móvil. Es gratuita, está inscrita en el registro del Banco de España y agrega bancos por PSD2. Lo que conviene mirar antes de quedarse es de dónde sale su dinero: su propia web explica que intermedia préstamos con más de cuarenta entidades, y también seguros y cuentas remuneradas.',
                'rows' => [
                    [
                        'dimension' => 'Precio y modelo de negocio',
                        'rival' => 'Gratis. Los ingresos vienen de intermediar préstamos, seguros y cuentas remuneradas: «intermediamos por ti con más de 40 entidades», dice su web (consultado el 18 de agosto de 2026).',
                        'whisper' => 'Plan gratuito y plan de pago por suscripción. No intermediamos ningún producto financiero, así que nadie nos paga por lo que aparece en tu pantalla.',
                    ],
                    [
                        'dimension' => 'Qué hay dentro de la app',
                        'rival' => 'Además del análisis de gastos, ofertas de préstamos, seguros y cuentas remuneradas.',
                        'whisper' => 'Solo tus cuentas y tus números. Ninguna oferta de terceros.',
                    ],
                    [
                        'dimension' => 'Qué puedes agregar',
                        'rival' => 'Cuentas y tarjetas de bancos españoles.',
                        'whisper' => 'Bancos por PSD2, más brokers (Indexa Capital, Interactive Brokers), cripto (Binance, Bitpanda, Coinbase) y Wise, todo dentro del mismo patrimonio neto.',
                    ],
                    [
                        'dimension' => 'Qué puedes comprobar',
                        'rival' => 'Código cerrado: su política de privacidad es lo que hay.',
                        'whisper' => 'El código es público en GitHub, así que puedes leer qué hace la aplicación con tus datos en lugar de creerte una promesa.',
                    ],
                ],
                'narrative_title' => 'La letra pequeña: una app gratis tiene que cobrar a alguien',
                'narrative' => [
                    'Que Fintonic sea gratis no es un truco, es una decisión de modelo de negocio, y está declarada en su propia web. Analiza tu perfil, lo compara con la oferta de más de cuarenta entidades y cobra a la entidad cuando el préstamo o el seguro sale adelante. Para ti no tiene coste. Para la app, tu historial de gastos es la materia prima con la que decide qué producto te encaja.',
                    'Eso cambia lo que la aplicación optimiza. Una app que vive de la intermediación tiene un motivo para conocer tu capacidad de endeudamiento; una que vive de una suscripción solo tiene un motivo para que sigas usándola el mes que viene. Quien paga marca la prioridad.',
                    'Whisper Money cobra una suscripción y tiene un plan gratuito sin publicidad. No hay comparador de préstamos, ni seguros, ni cuentas remuneradas, ni comisiones de nadie por lo que ves. Si algún día deja de resultarte útil, lo cancelas, y ese es todo el incentivo que tenemos.',
                ],
                'bullets' => [
                    'Conexión bancaria por PSD2 con bancos españoles y europeos, el mismo estándar que usa Fintonic.',
                    'Ni un solo producto financiero a la venta dentro de la aplicación.',
                    'Patrimonio neto consolidado: cuentas corrientes, ahorro, brokers, cripto, inmuebles y préstamos en un único número.',
                    'Integraciones directas con Indexa Capital, Interactive Brokers, Wise, Binance, Bitpanda y Coinbase, además de la banca.',
                    'Presupuestos por categoría con periodo semanal, quincenal, mensual o anual, y arrastre del sobrante al periodo siguiente.',
                    'Reglas de automatización propias: defines una vez cómo se categoriza un comercio y se aplica a todo lo que entra.',
                    'Categorización con IA opcional, que solo funciona si das tu consentimiento explícito.',
                    'Importación de extractos en CSV, XLS y XLSX con mapeo de columnas, para las cuentas que no conectan.',
                    'Aplicación web instalable en el móvil, en español, inglés o francés, con el código público y sin compartir tus datos con terceros.',
                ],
                'migration_intro' => 'Fintonic muestra los movimientos que le entrega tu banco, así que el origen fiable de tu histórico es el banco, no la app. El importador de Whisper Money está pensado justo para eso: coges el fichero del banco y él se encarga de entender las columnas.',
                'migration_steps' => [
                    [
                        'title' => 'Crea la cuenta y conecta tus bancos',
                        'body' => 'En el alta eliges tu entidad y autorizas el acceso por PSD2 en la web del propio banco, igual que en Fintonic. Nosotros no vemos ni guardamos tus credenciales. La primera sincronización trae el histórico que tu banco entregue, que varía de una entidad a otra; si quieres más años, sigue con el paso siguiente.',
                    ],
                    [
                        'title' => 'Descarga tu histórico del banco',
                        'body' => 'Entra en la banca online de cada cuenta y exporta los movimientos al periodo más amplio que te permita, en CSV, XLS o XLSX. Los ficheros de la banca española traen casi siempre las columnas Fecha, F. Valor, Concepto, Importe y Saldo. No hace falta tocarlas.',
                    ],
                    [
                        'title' => 'Abre el importador y elige la cuenta de destino',
                        'body' => 'En Transacciones abres el importador y seleccionas a qué cuenta van esos movimientos. Después arrastras el fichero o lo buscas: acepta .csv, .xls y .xlsx.',
                    ],
                    [
                        'title' => 'Confirma el mapeo de columnas',
                        'body' => 'El importador reconoce solo las cabeceras en español (Fecha, F. Valor, Concepto, Descripción, Importe, Saldo) y te propone el mapeo. Tú confirmas fecha, descripción e importe, y de forma opcional el saldo, el ordenante y el beneficiario. Puedes unir varias columnas en la descripción y elegir el formato de fecha entre DD-MM-YYYY, YYYY-MM-DD, MM-DD-YYYY y YYYYMMDD, con una vista previa de las tres primeras filas. El mapeo se guarda para esa cuenta, así que el siguiente fichero del mismo banco entra sin tocar nada.',
                    ],
                    [
                        'title' => 'Revisa los duplicados y confirma',
                        'body' => 'Antes de importar ves el total, cuántos movimientos están seleccionados y cuántos son duplicados. Los duplicados se comparan con lo que ya hay en la cuenta y vienen desmarcados, así que puedes repetir la importación de un mes sin miedo. Al confirmar se aplican tus reglas de automatización y, si has activado la IA, categoriza lo que quede suelto.',
                    ],
                ],
                'closing_body' => 'Ya hay gente gestionando aquí lo que antes miraba en Fintonic: las mismas cuentas, sin ofertas de préstamos por medio y con las inversiones dentro del total. El plan gratuito no pide tarjeta.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function ynab(): array
    {
        return [
            'testimonials' => [
                [
                    'name' => 'Priya Nair',
                    'gravatar' => '299c92b453769c8805a14f3044157f22',
                    'text' => [
                        'en' => 'Categorizing transactions used to eat my whole Sunday. Now the AI does it and gets most of them right, and the few it misses it remembers after I fix them once. I barely touch it anymore.',
                        'es' => 'Categorizar las transacciones me comía el domingo entero. Ahora lo hace la IA y acierta en casi todas, y las pocas que falla las recuerda en cuanto las corrijo una vez. Ya casi ni lo toco.',
                    ],
                ],
                [
                    'name' => 'Sofía Romero',
                    'gravatar' => '51bd48ebe85a4f936b1f2ac38ee39238',
                    'text' => [
                        'en' => "My accounts sync on their own and everything lands already sorted, so checking my budget takes two minutes instead of an hour. I didn't think I'd keep up with it, but I have.",
                        'es' => 'Mis cuentas se sincronizan solas y todo llega ya ordenado, así que revisar mi presupuesto me lleva dos minutos en vez de una hora. No pensé que fuese a mantenerlo al día, pero lo he conseguido.',
                    ],
                ],
            ],
            'en' => [
                'slug' => 'ynab-alternative',
                'rival' => 'YNAB',
                'title' => 'YNAB Alternative (2026) | Whisper Money',
                'description' => 'YNAB costs $14.99/month or $109/year billed in dollars, with direct import limited to selected banks. Whisper Money bills in euros, connects banks over PSD2 and imports files. Comparison and migration guide.',
                'heading' => 'YNAB alternative',
                'intro' => 'YNAB (You Need A Budget) is probably the budgeting method with the most devoted following in the world, and deservedly so: assigning every euro to a category before you spend it works. The problem in Europe is not the method, it is the logistics. The price is in dollars and automatic import only covers some European banks.',
                'rows' => [
                    [
                        'dimension' => 'Price',
                        'rival' => '$14.99/month or $109/year. Its pricing page warns that the rate is "priced in US dollars" and publishes no euro price (checked 18 August 2026).',
                        'whisper' => 'A free plan and a paid plan billed in euros, with the rate published on the pricing page.',
                    ],
                    [
                        'dimension' => 'Language',
                        'rival' => 'App, marketing site and help centre in English only.',
                        'whisper' => 'Interface in English, Spanish and French.',
                    ],
                    [
                        'dimension' => 'Bank connection',
                        'rival' => '"Direct import currently supports select US, Canadian, UK, and EU Banks." For everything else, file-based import.',
                        'whisper' => 'PSD2 connections with Spanish and European banks, plus file import when a bank does not connect.',
                    ],
                    [
                        'dimension' => 'Budgeting method',
                        'rival' => 'Envelopes: the whole available balance gets assigned before you spend it.',
                        'whisper' => 'Category budgets — weekly, biweekly, monthly or yearly — with optional rollover. You can budget only what you care about.',
                    ],
                ],
                'narrative_title' => 'The catch: the method travels, the typing does not have to',
                'narrative' => [
                    "YNAB's subscription is $109 a year billed in dollars, so what you actually pay depends on the day's exchange rate and on what your bank charges for a foreign currency transaction. That is the visible part.",
                    'The less visible part is the daily work. Its own page says it: direct import covers "select US, Canadian, UK, and EU Banks". If your bank is not in that group, the routine becomes downloading the statement and uploading it by hand every week, so the tool you bought to save time charges you twice — in dollars and in Sundays.',
                    'The good news is that what you learned in YNAB does not disappear when you switch apps. Setting category limits, reviewing the month and deciding before you spend are habits, not features of one particular product. Whisper Money holds them up with category budgets and rollover, billed in euros, with the bank syncing itself.',
                ],
                'bullets' => [
                    'PSD2 connections with Spanish and European banks, with no file to upload every week.',
                    'Billed in euros, so no currency conversion fee on your card.',
                    'Interface in English, Spanish and French.',
                    'Category budgets on a weekly, biweekly, monthly or yearly period.',
                    'Roll the leftover into the next period whenever you want, without being forced to account for every euro.',
                    'A budget that catches everything the others do not, so no spending slips through unclassified.',
                    'Automation rules to categorise by merchant, amount or text, without redoing the work every month.',
                    'Optional AI categorisation that learns from your corrections, if you consent to it.',
                    'Investments and crypto inside the same net worth: Indexa Capital, Interactive Brokers, Wise, Binance, Bitpanda and Coinbase.',
                    'CSV, XLS and XLSX import with column mapping for the accounts that do not connect.',
                ],
                'migration_intro' => 'YNAB exports your data, so the move is direct. In its web app, click the plan name in the left sidebar and choose "Export Plan": you get two files, one with the plan and its categories and one with the transaction history. The second is the one we want. You can also select one account\'s transactions and export just those.',
                'migration_steps' => [
                    [
                        'title' => 'Create your account and prepare the destination accounts',
                        'body' => 'Connect the banks that sync over PSD2 and create the ones that do not by hand. The destination account has to exist before you import, because the importer always asks where the transactions go.',
                    ],
                    [
                        'title' => 'Export from YNAB',
                        'body' => 'In the YNAB web app, plan name in the left sidebar, then "Export Plan". You get the transaction history as CSV, or as TSV if your currency uses the comma as its decimal separator. If it comes out as TSV, save it as CSV from your spreadsheet before uploading.',
                    ],
                    [
                        'title' => 'Merge Outflow and Inflow into one column',
                        'body' => 'YNAB splits the amount across two columns, Outflow and Inflow. Our importer expects one: in your spreadsheet, build a single amount column with expenses negative and income positive. It is the only manual edit in the whole move.',
                    ],
                    [
                        'title' => 'Upload the file and map the columns',
                        'body' => "Under Transactions, open the importer, pick the account and drag the file in. YNAB's file carries Date, Payee, Memo and Category: map Date to the date, Payee to the description — you can add Memo as a second column and they get joined — and your new column to the amount. You pick the date format from DD-MM-YYYY, YYYY-MM-DD, MM-DD-YYYY and YYYYMMDD, and you see the first three rows already parsed before carrying on.",
                    ],
                    [
                        'title' => 'Turn your categories into rules and import',
                        'body' => 'The preview gives you the total, the selected rows and the duplicates, which come unticked. On import your automation rules run, so writing four or five rules for your regular merchants puts much of the history back in place in one pass; whatever is left uncategorised the AI handles if you turn it on.',
                    ],
                ],
                'closing_body' => 'The method you learned in YNAB still holds. What changes is that here you apply it in euros, with the bank coming in on its own. The free plan asks for no card.',
            ],
            'es' => [
                'slug' => 'alternativa-a-ynab-en-espanol',
                'rival' => 'YNAB',
                'title' => 'Alternativa a YNAB en español (2026) | Whisper Money',
                'description' => 'YNAB cuesta 14,99 $/mes o 109 $/año y factura en dólares, con importación directa limitada a algunos bancos. Whisper Money está en español, conecta bancos por PSD2 e importa ficheros. Comparativa y guía de migración.',
                'heading' => 'Alternativa a YNAB en español',
                'intro' => 'YNAB (You Need A Budget) es probablemente el método de presupuesto con más devotos del mundo, y con razón: asignar cada euro a una categoría antes de gastarlo funciona. El problema en España no es el método, es la logística. El precio está en dólares, la aplicación y su documentación están en inglés y la importación automática solo cubre parte de los bancos europeos.',
                'rows' => [
                    [
                        'dimension' => 'Precio',
                        'rival' => '14,99 $/mes o 109 $/año. Su página de precios avisa de que la tarifa está «priced in US dollars» y no publica precio en euros (consultado el 18 de agosto de 2026).',
                        'whisper' => 'Plan gratuito y plan de pago facturado en euros, con la tarifa publicada en la página de precios.',
                    ],
                    [
                        'dimension' => 'Idioma',
                        'rival' => 'Aplicación, web comercial y centro de ayuda en inglés.',
                        'whisper' => 'Interfaz en español, inglés y francés.',
                    ],
                    [
                        'dimension' => 'Conexión bancaria',
                        'rival' => '«Direct import currently supports select US, Canadian, UK, and EU Banks». Para el resto, importación de fichero.',
                        'whisper' => 'Conexión por PSD2 con bancos españoles y europeos, e importación de fichero cuando la entidad no conecta.',
                    ],
                    [
                        'dimension' => 'Método de presupuesto',
                        'rival' => 'Sobres: el saldo disponible se asigna entero antes de gastar.',
                        'whisper' => 'Presupuestos por categoría —semanales, quincenales, mensuales o anuales— con arrastre opcional del sobrante. Puedes presupuestar solo lo que te interese.',
                    ],
                ],
                'narrative_title' => 'La trampa: el método viaja, teclear los datos no hace falta que viaje',
                'narrative' => [
                    'La suscripción de YNAB son 109 $ al año facturados en dólares, así que lo que acabas pagando depende del cambio del día y de lo que tu banco cobre por operar en divisa. Esa es la parte visible.',
                    'La menos visible es el trabajo diario. Su propia página lo dice: la importación directa cubre «select US, Canadian, UK, and EU Banks». Si tu entidad no está en ese grupo, la rutina consiste en descargar el extracto y subirlo a mano cada semana, con lo que la herramienta que compraste para ahorrarte tiempo te lo cobra dos veces, en dólares y en domingos.',
                    'La buena noticia es que lo que aprendiste en YNAB no se pierde al cambiar de app. Poner límites por categoría, revisar el mes y decidir antes de gastar son hábitos, no funciones de un producto concreto. Whisper Money los sostiene con presupuestos por categoría y arrastre del sobrante, en tu idioma y con el banco sincronizándose solo.',
                ],
                'bullets' => [
                    'Conexión por PSD2 con bancos españoles y europeos, sin subir un fichero cada semana.',
                    'Tarifa en euros, sin comisión de cambio de divisa en tu tarjeta.',
                    'Interfaz en español, sin traducir mentalmente «payee», «inflow» o «to be budgeted».',
                    'Presupuestos por categoría con periodo semanal, quincenal, mensual o anual.',
                    'Arrastre del sobrante al periodo siguiente cuando lo quieras, sin obligarte a cuadrar cada euro.',
                    'Un presupuesto que recoge lo que no encaja en ningún otro, para que no se te escape gasto sin clasificar.',
                    'Reglas de automatización para categorizar por comercio, importe o texto, sin repetir el trabajo cada mes.',
                    'Categorización con IA opcional que aprende de tus correcciones, si das tu consentimiento.',
                    'Inversiones y cripto en el mismo patrimonio neto: Indexa Capital, Interactive Brokers, Wise, Binance, Bitpanda y Coinbase.',
                    'Importación de CSV, XLS y XLSX con mapeo de columnas para las cuentas que no conectan.',
                ],
                'migration_intro' => 'YNAB exporta tus datos, así que la mudanza es directa. En su aplicación web pulsas el nombre del plan en la barra lateral izquierda y eliges «Export Plan»: obtienes dos ficheros, uno con el plan y las categorías y otro con el histórico de movimientos. El segundo es el que vamos a usar. También puedes seleccionar los movimientos de una cuenta y exportar solo esos.',
                'migration_steps' => [
                    [
                        'title' => 'Crea la cuenta y prepara las cuentas de destino',
                        'body' => 'Conecta por PSD2 los bancos que sincronicen y crea a mano los que no. Necesitas la cuenta de destino creada antes de importar, porque el importador siempre pregunta a dónde van los movimientos.',
                    ],
                    [
                        'title' => 'Exporta desde YNAB',
                        'body' => 'En la web de YNAB, nombre del plan en la barra lateral izquierda y «Export Plan». Descargas el histórico de movimientos en CSV, o en TSV si tu moneda usa la coma como decimal. Si te sale en TSV, guárdalo como CSV desde tu hoja de cálculo antes de subirlo.',
                    ],
                    [
                        'title' => 'Une Outflow e Inflow en una sola columna',
                        'body' => 'YNAB reparte el importe en dos columnas, Outflow e Inflow. Nuestro importador espera una sola: en tu hoja de cálculo crea una columna de importe con los gastos en negativo y los ingresos en positivo. Es la única edición manual de toda la mudanza.',
                    ],
                    [
                        'title' => 'Sube el fichero y mapea las columnas',
                        'body' => 'En Transacciones abres el importador, eliges la cuenta y arrastras el fichero. El fichero de YNAB trae Date, Payee, Memo y Category: asignas Date a la fecha, Payee a la descripción —puedes añadir Memo como segunda columna y se unen— y tu columna nueva al importe. El formato de fecha lo eliges entre DD-MM-YYYY, YYYY-MM-DD, MM-DD-YYYY y YYYYMMDD, y ves las tres primeras filas ya interpretadas antes de continuar.',
                    ],
                    [
                        'title' => 'Convierte tus categorías en reglas e importa',
                        'body' => 'La vista previa te da el total, los seleccionados y los duplicados, que vienen desmarcados. Al importar se aplican tus reglas de automatización, así que crear cuatro o cinco reglas para tus comercios habituales recoloca buena parte del histórico de una pasada; lo que quede sin categoría lo resuelve la IA si la activas.',
                    ],
                ],
                'closing_body' => 'El método que aprendiste en YNAB sigue valiendo. Lo que cambia es que aquí lo aplicas en euros, en español y con el banco entrando solo. El plan gratuito no pide tarjeta.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function spreadsheets(): array
    {
        return [
            'testimonials' => [
                [
                    'name' => 'Carla Álvarez',
                    'gravatar' => '9901ee5e849cf9a0caea00e897cb8123',
                    'text' => [
                        'en' => 'I found Whisper Money and instantly knew I needed it. I was stuck doing everything in a spreadsheet — a chore I kept putting off. This makes it effortless.',
                        'es' => 'Descubrí Whisper Money y supe al instante que lo necesitaba. Estaba haciéndolo todo en una hoja de cálculo, una tarea que acababa posponiendo siempre. Esto lo hace facilísimo.',
                    ],
                ],
                [
                    'name' => 'Haru',
                    'gravatar' => '3e52d6b2cbefb0fa2a572a588b3f7953',
                    'text' => [
                        'en' => 'I used to keep everything in a spreadsheet and it took forever — now I have my bank under control and add cash payments manually. Great work!',
                        'es' => 'Antes lo ponía todo en un Excel y me llevaba muchísimo tiempo; ahora tengo el banco controlado y los pagos en efectivo los añado manualmente. ¡Gran trabajo!',
                    ],
                ],
            ],
            'en' => [
                'slug' => 'excel-and-google-sheets-alternative',
                'rival' => 'Excel and Google Sheets',
                'title' => 'Excel and Google Sheets Alternative for Personal Finance (2026) | Whisper Money',
                'description' => 'A spreadsheet is free and perfect until you stop maintaining it. Whisper Money keeps you in control of the categories and takes the data entry off your hands. Comparison and a guide to importing your sheet.',
                'heading' => 'Excel and Google Sheets alternative for your finances',
                'intro' => 'A spreadsheet is the best personal finance tool there is, right up to the day you stop maintaining it. Google Sheets is free, Excel comes with Microsoft 365, it does exactly what you tell it, and the file is yours forever. Almost everyone who abandons one does so for the same reason, and it is not a lack of power: it is the work of entering the data.',
                'rows' => [
                    [
                        'dimension' => 'Price',
                        'rival' => 'Google Sheets is free and Excel comes with Microsoft 365. On money alone, unbeatable.',
                        'whisper' => 'A free plan and a paid plan. We do not win this comparison on price: we win it on the time it gives back.',
                    ],
                    [
                        'dimension' => 'Where the data comes from',
                        'rival' => 'You paste it in: download the statement, fix the columns, paste underneath, repair the dates and decimals.',
                        'whisper' => 'From the bank over PSD2, or from the same statement you were already downloading, with the columns detected and the duplicates flagged.',
                    ],
                    [
                        'dimension' => 'Categories',
                        'rival' => 'VLOOKUP, dropdown lists and some nested IF only you understand.',
                        'whisper' => 'Automation rules that apply to everything arriving, plus optional AI categorisation.',
                    ],
                    [
                        'dimension' => 'What happens if you skip a month',
                        'rival' => 'You come back to a file with a hole in it and a sense of unfinished homework.',
                        'whisper' => 'Connected accounts keep syncing: when you come back, the month is already there.',
                    ],
                ],
                'narrative_title' => 'The problem is not the spreadsheet, it is the upkeep',
                'narrative' => [
                    'A well-built sheet gives you something no app does: total control of the calculation. If yours works and you keep it current, there is no reason at all to switch.',
                    'The real reason people abandon them is boring and mechanical. Every month you download each bank\'s statement, adjust the columns because every bank names them differently, repair the dates and the decimals, paste without duplicating last month, and re-label merchants you have already labelled twelve times. It is work that produces no new information. The first time you skip it, the file stops being current; the second time, it stops being any use for deciding anything.',
                    'Whisper Money does not try to be more flexible than your sheet, because it will not be. It tries to take that upkeep away. The banks that connect come in on their own, the ones that do not come in through the same file you were already downloading, and categories apply through rules you write once. The free plan is permanent, so trying it commits you to nothing.',
                ],
                'bullets' => [
                    'No copy and paste: the banks that connect over PSD2 come in on their own every day.',
                    'For the rest, the same statement you were already downloading, with headers detected automatically.',
                    'Duplicates detected and unticked before importing, so you can redo a month without dirtying the data.',
                    'The column mapping is saved per account: the second file from the same bank needs no setup.',
                    'Automation rules instead of VLOOKUP.',
                    'Net worth worked out for you, across accounts, brokers, crypto, property and loans.',
                    'Category budgets with alerts, and no conditional formatting.',
                    'Multi-currency across 33 currencies with conversion, and no exchange rate tab to maintain.',
                    'Runs in the browser and installs on your phone, so logging cash no longer waits until you reach a computer.',
                    'Interface in English, Spanish and French, public code, and no data shared with third parties.',
                ],
                'migration_intro' => 'This is the cleanest move of them all, because your history is already in the shape the importer expects: a sheet with one row per transaction.',
                'migration_steps' => [
                    [
                        'title' => 'Create the accounts you had as tabs',
                        'body' => 'Each tab or block in your sheet usually maps to a real account. Create them in Whisper Money, or connect the bank directly if it syncs, before importing anything.',
                    ],
                    [
                        'title' => 'Prepare the sheet without reformatting it',
                        'body' => 'Nothing needs rebuilding. All it takes is one row per transaction and a date column, a description column and an amount column with expenses negative. If you kept expense and income in separate columns, merge them into one signed column.',
                    ],
                    [
                        'title' => 'Save or export the file',
                        'body' => 'Excel works as is: the importer accepts .xls and .xlsx. In Google Sheets, File → Download → CSV or Microsoft Excel. If your sheet has several tabs, export and import them one at a time, each into its own account.',
                    ],
                    [
                        'title' => 'Upload the file and confirm the mapping',
                        'body' => 'On upload the headers get detected and a mapping is proposed. You confirm date, description and amount, and optionally balance. You pick the date format from DD-MM-YYYY, YYYY-MM-DD, MM-DD-YYYY and YYYYMMDD and see the first three rows already parsed before continuing. The mapping is saved for that account.',
                    ],
                    [
                        'title' => 'Rebuild the categories as rules',
                        'body' => 'In the preview you see the total, the selected rows and the duplicates. On import your automation rules run: if you had a VLOOKUP turning "MERCADONA" into "Groceries", that becomes a rule which will also apply to everything arriving in future. Whatever is left uncategorised the AI handles if you turn it on.',
                    ],
                ],
                'closing_body' => 'Nobody misses maintaining the sheet, only the control. You keep that here: you choose the categories, you write the rules, and you decide what connects and what does not. The free plan asks for no card.',
            ],
            'es' => [
                'slug' => 'alternativa-a-excel-y-google-sheets',
                'rival' => 'Excel y Google Sheets',
                'title' => 'Alternativa a Excel y Google Sheets para finanzas personales (2026) | Whisper Money',
                'description' => 'La hoja de cálculo es gratis y perfecta hasta que dejas de mantenerla. Whisper Money conserva el control de las categorías y te quita el trabajo de meter los datos. Comparativa y guía para importar tu hoja.',
                'heading' => 'Alternativa a Excel y Google Sheets para tus finanzas',
                'intro' => 'La hoja de cálculo es la mejor herramienta de finanzas personales que existe, hasta el día en que dejas de mantenerla. Google Sheets es gratuito, Excel viene incluido con Microsoft 365, hace exactamente lo que le pidas y el fichero es tuyo para siempre. Casi todo el mundo que la abandona lo hace por el mismo motivo, y no es la potencia: es el trabajo de meter los datos.',
                'rows' => [
                    [
                        'dimension' => 'Precio',
                        'rival' => 'Google Sheets es gratuito y Excel viene con Microsoft 365. En dinero, imbatible.',
                        'whisper' => 'Plan gratuito y plan de pago. Esta comparación no la ganamos en precio: la ganamos en el tiempo que te devuelve.',
                    ],
                    [
                        'dimension' => 'De dónde salen los datos',
                        'rival' => 'Los pegas tú: descargar el extracto, ajustar columnas, pegar debajo, arreglar fechas y decimales.',
                        'whisper' => 'Del banco por PSD2, o del mismo extracto que ya descargabas, con las columnas detectadas y los duplicados marcados.',
                    ],
                    [
                        'dimension' => 'Categorías',
                        'rival' => 'BUSCARV, listas desplegables y algún SI anidado que solo entiendes tú.',
                        'whisper' => 'Reglas de automatización que se aplican a todo lo que entra, y categorización con IA opcional.',
                    ],
                    [
                        'dimension' => 'Qué pasa si lo dejas un mes',
                        'rival' => 'Vuelves a un fichero con un hueco y la sensación de deuda pendiente.',
                        'whisper' => 'Las cuentas conectadas siguen sincronizando: cuando vuelves, el mes ya está.',
                    ],
                ],
                'narrative_title' => 'El problema no es la hoja de cálculo, es el mantenimiento',
                'narrative' => [
                    'Una hoja bien montada te da algo que ninguna aplicación te da: control absoluto del cálculo. Si la tuya funciona y la mantienes al día, no hay ninguna razón para cambiar.',
                    'El motivo real por el que se abandonan es aburrido y mecánico. Cada mes hay que descargar los extractos de cada banco, ajustar las columnas porque cada entidad las nombra distinto, arreglar las fechas y los decimales, pegar sin duplicar lo del mes anterior y volver a etiquetar comercios que ya etiquetaste doce veces. Es trabajo que no produce ninguna información nueva. La primera vez que te lo saltas, el fichero deja de estar al día; la segunda, deja de servir para decidir nada.',
                    'Whisper Money no intenta ser más flexible que tu hoja, porque no lo será: intenta quitarte ese mantenimiento. Los bancos que conectan entran solos, los que no entran por el mismo fichero que ya descargabas, y las categorías se aplican con reglas que escribes una vez. El plan gratuito es permanente, así que probarlo no te obliga a nada.',
                ],
                'bullets' => [
                    'Nada de copiar y pegar: los bancos que conectan por PSD2 entran solos cada día.',
                    'Para el resto, el mismo extracto que ya descargabas, con las cabeceras en español detectadas automáticamente.',
                    'Duplicados detectados y desmarcados antes de importar, así que puedes repetir un mes sin ensuciar los datos.',
                    'El mapeo de columnas se guarda por cuenta: el segundo fichero del mismo banco entra sin configurar nada.',
                    'Reglas de automatización en lugar de BUSCARV.',
                    'Patrimonio neto calculado solo, con cuentas, brokers, cripto, inmuebles y préstamos.',
                    'Presupuestos por categoría con avisos, sin formatos condicionales.',
                    'Multidivisa con 33 monedas y conversión, sin mantener una pestaña de tipos de cambio.',
                    'Funciona en el navegador y se instala en el móvil, así que apuntar el efectivo deja de esperar a que llegues al ordenador.',
                    'Interfaz en español, inglés y francés, código público y ningún dato compartido con terceros.',
                ],
                'migration_intro' => 'Esta es la mudanza más limpia de todas, porque tu histórico ya está en el formato que el importador espera: una hoja con una fila por movimiento.',
                'migration_steps' => [
                    [
                        'title' => 'Crea las cuentas que tenías en pestañas',
                        'body' => 'Cada pestaña o cada bloque de tu hoja suele corresponder a una cuenta real. Créalas en Whisper Money, o conecta directamente el banco si sincroniza, antes de importar nada.',
                    ],
                    [
                        'title' => 'Prepara la hoja sin reformatearla',
                        'body' => 'No hace falta rehacer nada. Basta con que cada movimiento sea una fila y que existan una columna de fecha, una de concepto y una de importe con los gastos en negativo. Si llevabas gasto e ingreso en columnas separadas, únelos en una sola con signo.',
                    ],
                    [
                        'title' => 'Guarda o exporta el fichero',
                        'body' => 'Excel vale tal cual: el importador acepta .xls y .xlsx. En Google Sheets, Archivo → Descargar → CSV o Microsoft Excel. Si tu hoja tiene varias pestañas, expórtalas e impórtalas una a una, cada una a su cuenta.',
                    ],
                    [
                        'title' => 'Sube el fichero y confirma el mapeo',
                        'body' => 'Al subirlo se detectan las cabeceras y se propone el mapeo. Confirmas fecha, descripción e importe, y de forma opcional el saldo. Eliges el formato de fecha entre DD-MM-YYYY, YYYY-MM-DD, MM-DD-YYYY y YYYYMMDD y ves las tres primeras filas ya interpretadas antes de seguir. El mapeo queda guardado para esa cuenta.',
                    ],
                    [
                        'title' => 'Reconstruye las categorías con reglas',
                        'body' => 'En la vista previa ves el total, los seleccionados y los duplicados. Al importar se aplican tus reglas de automatización: si antes tenías un BUSCARV que convertía «MERCADONA» en «Supermercado», eso pasa a ser una regla que además se aplicará a todo lo que llegue en el futuro. Lo que quede sin categoría lo resuelve la IA si la activas.',
                    ],
                ],
                'closing_body' => 'Nadie echa de menos el mantenimiento de la hoja, solo el control. Aquí lo conservas: eliges las categorías, escribes las reglas y decides qué se conecta y qué no. El plan gratuito no pide tarjeta.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function bankApp(): array
    {
        return [
            'testimonials' => [
                [
                    'name' => 'Marc Dubois',
                    'gravatar' => '226dbaa3b8d04f4641b99ab90884bb9d',
                    'text' => [
                        'en' => "I had three apps and a spreadsheet before this. Getting every account in one place is the first time I've actually understood where my money goes.",
                        'es' => 'Antes tenía tres apps y una hoja de cálculo. Tener todas las cuentas en un sitio es la primera vez que entiendo de verdad a dónde va mi dinero.',
                    ],
                ],
                [
                    'name' => 'Verónica Betancourt',
                    'text' => [
                        'en' => "I love the concept of the app. It's exactly the tool I had spent a long time looking for, precisely to manage my finances in one place and have a view of everything.",
                        'es' => 'Me encanta el concepto de la app. Justo es una herramienta que venía buscando hace mucho, precisamente para manejar mis finanzas en un solo lugar y tener una vista de todo.',
                    ],
                ],
            ],
            'en' => [
                'slug' => 'bank-app-alternative',
                'rival' => "your bank's app",
                'title' => "An Alternative to Your Bank's App for Tracking Spending (2026) | Whisper Money",
                'description' => "Your bank's app only sees what passes through that bank. Whisper Money brings every institution together over PSD2, plus brokers, crypto, property and loans, with categories you define. Comparison and migration guide.",
                'heading' => "An alternative to your bank's app",
                'intro' => "Your bank's app is free, already installed, and its spending charts have improved a great deal in recent years. For anyone with one account and no investments it is usually enough, and saying otherwise would be selling you something you do not need. The useful question is a different one: what happens when you have two banks, a neobank, a broker and some crypto.",
                'rows' => [
                    [
                        'dimension' => 'Price',
                        'rival' => 'Free, included with your account.',
                        'whisper' => 'A free plan and a paid plan. Paying for it only makes sense if your money sits in more than one place.',
                    ],
                    [
                        'dimension' => 'What it can see',
                        'rival' => 'Whatever passes through that bank. Transactions at other institutions do not exist for it.',
                        'whisper' => 'All your banks over PSD2 on one screen, plus brokers, crypto, Wise, property and loans.',
                    ],
                    [
                        'dimension' => 'Categories',
                        'rival' => "The bank's, with the bank's logic, and no rules of your own.",
                        'whisper' => 'Yours, with rules you write once that apply themselves to everything arriving.',
                    ],
                    [
                        'dimension' => 'What happens if you switch banks',
                        'rival' => 'You start from scratch: the analysis ends where the commercial relationship ends.',
                        'whisper' => 'You connect the new account or import the old one\'s statement, and the series carries on.',
                    ],
                ],
                'narrative_title' => 'The limit: your bank can only tell you its half',
                'narrative' => [
                    "Your bank's app does not have a design flaw, it has a perimeter limit. It can only classify what passes through its own accounts, because that is all it sees. Once the salary lands in one bank, the mortgage leaves another, the neobank card pays for the trips and the contributions go to a broker, each app gives you a slice and none of them gives you the total.",
                    'And the total is exactly the number that decides things: how much you have, how much comes in, how much goes out and how it moves month to month. You do not work that out by adding up four screenshots at month end, not least because nobody does it two months running.',
                    "The second limit is who owns the history. The categories in your bank's app are its own, and its analysis ends the day you switch institutions. Here you define the categories, the history imports from any statement, and accounts at several institutions live in the same series.",
                ],
                'bullets' => [
                    'Every bank on one screen, over the same PSD2 standard your bank uses to let you in.',
                    "We never ask for your bank credentials: you sign the authorisation on the institution's own website and can revoke it there.",
                    'Brokers, crypto and Wise in the same net worth: Indexa Capital, Interactive Brokers, Binance, Bitpanda and Coinbase.',
                    'Property and loans included, so net worth is the real figure and not just the liquid part.',
                    'Your own categories and automation rules, instead of whatever classification the bank decides on.',
                    'Budgets that cross institutions: groceries are groceries even when you pay with three different cards.',
                    'Monthly cash flow of income against spending, with every account added up.',
                    'Multi-currency across 33 currencies with conversion, useful if you earn or spend outside the euro.',
                    'No financial products on offer: we do not sell mortgages, funds or insurance.',
                    'A web app installable on your phone, in English, Spanish and French, with public code.',
                ],
                'migration_intro' => "You do not need to abandon your bank's app: it is still where you make transfers. What moves is the analysis. And the file you need is one you already know how to download.",
                'migration_steps' => [
                    [
                        'title' => 'Connect each institution',
                        'body' => 'During sign-up you pick the bank and authorise access on its own website, with your usual authentication factors. We receive a read permission, not your keys, and the authorisation renews — or gets revoked — whenever you want.',
                    ],
                    [
                        'title' => 'Download whatever history the connection does not bring',
                        'body' => 'The sync pulls whatever history each institution hands over, which is not always everything. For earlier years, log in to online banking and export the transactions in CSV, XLS or XLSX: one download per account.',
                    ],
                    [
                        'title' => 'Import each statement into its account',
                        'body' => 'Under Transactions, open the importer, pick the account that is already connected and upload the file. Transactions the connection already brought are detected as duplicates and come unticked, so you can overlap periods without duplicating anything.',
                    ],
                    [
                        'title' => 'Confirm the mapping, which is usually already done',
                        'body' => 'Spanish banking headers — Fecha, F. Valor, Concepto, Descripción, Importe, Saldo — are recognised automatically. You confirm date, description and amount, pick the date format (DD-MM-YYYY for most Spanish files) and see the first three rows parsed. The mapping stays saved for that account.',
                    ],
                    [
                        'title' => 'Let the rules do the rest',
                        'body' => "On import your automation rules run and, if you turn it on, the AI categorises whatever is left. From then on the connected accounts come in by themselves and your bank's app goes back to being what it was: the place where you move money.",
                    ],
                ],
                'closing_body' => "Your bank's app will still be there for moving money. What changes is that there is now somewhere to see everything you have, not just the share that belongs to one institution. The free plan asks for no card.",
            ],
            'es' => [
                'slug' => 'alternativa-a-la-app-de-tu-banco',
                'rival' => 'la app de tu banco',
                'title' => 'Alternativa a la app de tu banco para controlar tus gastos (2026) | Whisper Money',
                'description' => 'La app de tu banco solo ve lo que pasa por ese banco. Whisper Money reúne todas tus entidades por PSD2, más brokers, cripto, inmuebles y préstamos, con categorías tuyas. Comparativa y guía de migración.',
                'heading' => 'Alternativa a la app de tu banco',
                'intro' => 'La app de tu banco es gratis, ya la tienes instalada y sus gráficos de gasto han mejorado mucho en los últimos años. Para quien tiene una sola cuenta y ninguna inversión suele ser suficiente, y decir lo contrario sería venderte algo que no necesitas. La pregunta útil es otra: qué pasa cuando tienes dos bancos, un neobanco, un broker y algo de cripto.',
                'rows' => [
                    [
                        'dimension' => 'Precio',
                        'rival' => 'Gratis, incluida en tu cuenta.',
                        'whisper' => 'Plan gratuito y plan de pago. Solo tiene sentido pagarlo si tienes dinero en más de un sitio.',
                    ],
                    [
                        'dimension' => 'Qué alcanza a ver',
                        'rival' => 'Lo que pasa por ese banco. Los movimientos de las demás entidades no existen para ella.',
                        'whisper' => 'Todos tus bancos por PSD2 en la misma pantalla, más brokers, cripto, Wise, inmuebles y préstamos.',
                    ],
                    [
                        'dimension' => 'Categorías',
                        'rival' => 'Las que decide el banco, con la lógica del banco y sin reglas propias.',
                        'whisper' => 'Las tuyas, con reglas que escribes una vez y se aplican solas a lo que entra.',
                    ],
                    [
                        'dimension' => 'Qué pasa si cambias de banco',
                        'rival' => 'Empiezas de cero: el análisis termina donde termina la relación comercial.',
                        'whisper' => 'Conectas la cuenta nueva o importas el extracto de la antigua, y la serie continúa.',
                    ],
                ],
                'narrative_title' => 'El límite: tu banco solo puede contarte su mitad',
                'narrative' => [
                    'La app del banco no tiene un defecto de diseño, tiene un límite de perímetro. Solo puede clasificar lo que pasa por sus cuentas, porque es lo único que ve. En cuanto la nómina entra en un banco, la hipoteca sale de otro, la tarjeta del neobanco paga los viajes y las aportaciones van a un broker, cada app te da una porción y ninguna te da el total.',
                    'Y el total es justamente el número que decide cosas: cuánto tienes, cuánto entra, cuánto sale y cómo evoluciona mes a mes. Eso no se calcula sumando cuatro capturas de pantalla a final de mes, entre otras cosas porque nadie lo hace dos meses seguidos.',
                    'El segundo límite es de quién es el histórico. Las categorías de la app de tu banco son suyas, y su análisis termina el día que te cambias de entidad. Aquí las categorías las defines tú, el histórico se importa desde cualquier extracto y las cuentas de varias entidades conviven en la misma serie.',
                ],
                'bullets' => [
                    'Todos los bancos en una pantalla, por el mismo estándar PSD2 que tu banco usa para dejarte entrar.',
                    'Nunca te pedimos las credenciales de tu banco: la autorización se firma en la web de la propia entidad y se puede revocar allí.',
                    'Brokers, cripto y Wise en el mismo patrimonio neto: Indexa Capital, Interactive Brokers, Binance, Bitpanda y Coinbase.',
                    'Inmuebles y préstamos incluidos, para que el patrimonio neto sea el real y no solo el líquido.',
                    'Categorías propias y reglas de automatización, en lugar de la clasificación que decida el banco.',
                    'Presupuestos que cruzan entidades: el gasto en supermercado es el mismo aunque pagues con tres tarjetas distintas.',
                    'Flujo de caja mensual de ingresos contra gastos, con todas las cuentas sumadas.',
                    'Multidivisa con 33 monedas y conversión, útil si cobras o gastas fuera del euro.',
                    'Ninguna oferta de producto financiero: no vendemos hipotecas, ni fondos, ni seguros.',
                    'Aplicación web instalable en el móvil, en español, inglés y francés, con el código público.',
                ],
                'migration_intro' => 'No hace falta abandonar la app de tu banco: sigue siendo donde haces las transferencias. Lo que se muda es el análisis. Y el fichero que necesitas es uno que ya sabes descargar.',
                'migration_steps' => [
                    [
                        'title' => 'Conecta cada entidad',
                        'body' => 'En el alta eliges el banco y autorizas el acceso en su propia web, con tus factores de autenticación habituales. Nosotros recibimos un permiso de lectura, no tus claves, y la autorización se renueva —o se revoca— cuando quieras.',
                    ],
                    [
                        'title' => 'Descarga el histórico que la conexión no traiga',
                        'body' => 'La sincronización trae el histórico que cada entidad entregue, que no siempre es todo. Para los años anteriores, entra en la banca online y exporta los movimientos en CSV, XLS o XLSX: una descarga por cuenta.',
                    ],
                    [
                        'title' => 'Importa cada extracto a su cuenta',
                        'body' => 'En Transacciones abres el importador, eliges la cuenta que ya está conectada y subes el fichero. Los movimientos que la conexión ya trajo se detectan como duplicados y vienen desmarcados, así que puedes solapar periodos sin duplicar nada.',
                    ],
                    [
                        'title' => 'Confirma el mapeo, que casi siempre viene hecho',
                        'body' => 'Las cabeceras de la banca española —Fecha, F. Valor, Concepto, Descripción, Importe, Saldo— se reconocen automáticamente. Confirmas fecha, descripción e importe, eliges el formato de fecha (DD-MM-YYYY en la mayoría de los ficheros españoles) y ves las tres primeras filas interpretadas. El mapeo queda guardado para esa cuenta.',
                    ],
                    [
                        'title' => 'Deja que las reglas hagan el resto',
                        'body' => 'Al importar se aplican tus reglas de automatización y, si la activas, la IA categoriza lo que quede suelto. A partir de ahí las cuentas conectadas entran solas y la app de tu banco vuelve a ser lo que era: el sitio donde mueves dinero.',
                    ],
                ],
                'closing_body' => 'La app de tu banco seguirá ahí para mover dinero. Lo que cambia es que ahora hay un sitio donde ver todo lo que tienes, no solo la parte que le corresponde a una entidad. El plan gratuito no pide tarjeta.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function wallet(): array
    {
        return [
            'testimonials' => [
                [
                    'name' => 'Lena Hoffmann',
                    'gravatar' => '708c64d04836b9a1e25a9caddc13f97b',
                    'text' => [
                        'en' => 'I drop in my bank’s CSV and it works out the columns and categories on its own. The part of doing my finances I used to dread is just gone.',
                        'es' => 'Suelto el CSV de mi banco y averigua las columnas y las categorías él solo. La parte de ponerme con mis finanzas que más pereza me daba ha desaparecido.',
                    ],
                ],
                [
                    'name' => 'Mark',
                    'text' => [
                        'en' => "I've tried a load of apps that never quite fitted. Apps also scare me a little, because years ago I spent months entering data into one that was working nicely — I was on the paid version — and six months later it disappeared and I lost all the information, and above all the time. Yours looks very good.",
                        'es' => 'He probado un montón de aplicaciones que no terminaban de cuadrar. Las aplicaciones también me dan un poco de miedo porque hace años estuve meses metiendo datos en una que iba guay, yo tenía versión de pago, pero a los 6 meses desapareció y perdí toda la información y sobre todo el tiempo. La vuestra pinta muy bien.',
                    ],
                ],
            ],
            'en' => [
                'slug' => 'wallet-budgetbakers-alternative',
                'rival' => 'Wallet (BudgetBakers)',
                'title' => 'Wallet by BudgetBakers Alternative (2026) | Whisper Money',
                'description' => 'Wallet by BudgetBakers publishes no price and its transaction export is a premium feature. Whisper Money publishes its price, includes importing in the free plan, and adds investments and crypto.',
                'heading' => 'An alternative to Wallet, by BudgetBakers',
                'intro' => 'Wallet, from the Czech company BudgetBakers, is one of the longest-running finance managers in Europe: it claims more than 14 million downloads and more than 15,000 bank connections, and it is among the highest-grossing finance apps in Spain. It is a solid product with years behind it. The differences are in the price, which it does not publish, and in what it costs to move your own data.',
                'rows' => [
                    [
                        'dimension' => 'Price',
                        'rival' => 'Publishes no rate on its site: it mentions a free trial and premium users, with no figures (checked 18 August 2026).',
                        'whisper' => 'Price published on the pricing page, with a free plan and a paid plan in euros.',
                    ],
                    [
                        'dimension' => 'Moving your history',
                        'rival' => 'Exports to CSV and XLS from its web app by selecting transactions. Its help centre states the export is a premium feature and that it does not include budgets, goals or recurring payments.',
                        'whisper' => 'Importing CSV, XLS and XLSX with column mapping is included in the free plan.',
                    ],
                    [
                        'dimension' => 'Investments and crypto',
                        'rival' => 'Focused on accounts, cards and budgeting.',
                        'whisper' => 'Brokers and exchanges inside net worth: Indexa Capital, Interactive Brokers, Binance, Bitpanda, Coinbase and Wise.',
                    ],
                    [
                        'dimension' => 'What you can verify',
                        'rival' => 'Closed source.',
                        'whisper' => 'Public code on GitHub.',
                    ],
                ],
                'narrative_title' => 'What it costs to leave',
                'narrative' => [
                    'The interesting comparison with Wallet is not about features, because it has nearly all of them and has had them for longer than we have. It is about reversibility: what you take with you if you ever decide to go.',
                    'Its own help centre documents the route. In the web app, the Records section, you filter, select the transactions and choose "Export to CSV" or "Export to xls". The same page states that it is a premium feature, and that what comes out is the transactions — date, amount, category and account — not your budgets, your goals or the recurring payments you set up. Which is to say: part of what you built over years does not travel with you.',
                    'The other difference is the price, which its site does not publish. Knowing what you will pay before installing anything is not a cosmetic detail: it is the first thing you want to compare while you are still deciding.',
                    'None of this makes Wallet a bad application. They are two different product decisions, and both can be verified today on their respective pages.',
                ],
                'bullets' => [
                    'Price published on the site, with nothing to install to find it out.',
                    'A free plan with statement importing included, not behind the paid plan.',
                    'The CSV or XLS file you export from Wallet goes straight in, with column mapping and duplicate detection.',
                    'The mapping is saved per account: the second import from the same source needs no setup.',
                    'Brokers, crypto and Wise integrated into net worth.',
                    'Property and loans, for the complete net worth figure.',
                    'Your own automation rules, to rebuild your categories in a single pass.',
                    'Optional AI categorisation that learns from your corrections, if you consent to it.',
                    'A full web app, not only mobile, and installable as an app on your phone.',
                    'Interface in English, Spanish and French, 33 currencies, and public code on GitHub.',
                ],
                'migration_intro' => 'Wallet exports to CSV and XLS, which are exactly two of the formats the importer expects. The move is one file per account.',
                'migration_steps' => [
                    [
                        'title' => 'Export from Wallet',
                        'body' => 'In its web app, go to Records, filter by account and by the widest date range you have, click "Select all" and use the Export button to choose "Export to CSV" or "Export to xls". Repeat account by account, so each file matches one destination account here.',
                    ],
                    [
                        'title' => 'Create or connect the destination account',
                        'body' => 'If your bank connects over PSD2, connect it and let it bring in the recent transactions. If not, create the account by hand. The importer always asks which account the transactions go to.',
                    ],
                    [
                        'title' => 'Upload the file',
                        'body' => 'Under Transactions, open the importer, select the account and drag in the .csv or .xls you just exported. It also accepts .xlsx.',
                    ],
                    [
                        'title' => 'Review the column mapping',
                        'body' => 'The importer proposes columns from their headers. You confirm date, description and amount, and you can join several columns into the description — the note and the payee, for instance — and pick the date format from four options. The three-row preview tells you whether it guessed right before you continue, and the mapping is saved for that account.',
                    ],
                    [
                        'title' => 'Recover the categories as rules',
                        'body' => "Wallet's export carries each transaction's category, but not your rules. On import, the automation rules you have created here run, and whatever is left unclassified the AI handles if you turn it on. In the preview you see the total, the selected rows and the duplicates before confirming.",
                    ],
                ],
                'closing_body' => 'If you already have the export, the move is five minutes per account. And if you only want to try before deciding, the free plan includes importing and asks for no card.',
            ],
            'es' => [
                'slug' => 'alternativa-a-wallet-budgetbakers',
                'rival' => 'Wallet (BudgetBakers)',
                'title' => 'Alternativa a Wallet de BudgetBakers (2026) | Whisper Money',
                'description' => 'Wallet de BudgetBakers no publica su tarifa y su exportación de movimientos es una función premium. Whisper Money publica el precio, incluye la importación en el plan gratuito y añade inversiones y cripto.',
                'heading' => 'Alternativa a Wallet, de BudgetBakers',
                'intro' => 'Wallet, de la checa BudgetBakers, es uno de los gestores más veteranos de Europa: declara más de 14 millones de descargas y más de 15.000 conexiones bancarias, y está entre las apps de finanzas que más facturan en España. Es un producto sólido y con años de recorrido. Las diferencias están en el precio, que no publica, y en lo que cuesta mover tus propios datos.',
                'rows' => [
                    [
                        'dimension' => 'Precio',
                        'rival' => 'No publica tarifa en su web: habla de prueba gratuita y de usuarios premium, sin cifras (consultado el 18 de agosto de 2026).',
                        'whisper' => 'Precio publicado en la página de precios, con plan gratuito y plan de pago en euros.',
                    ],
                    [
                        'dimension' => 'Mover tu histórico',
                        'rival' => 'Exporta a CSV y a XLS desde su web seleccionando movimientos. Su centro de ayuda indica que la exportación es una función premium y que no incluye presupuestos, objetivos ni pagos recurrentes.',
                        'whisper' => 'La importación de CSV, XLS y XLSX con mapeo de columnas está incluida en el plan gratuito.',
                    ],
                    [
                        'dimension' => 'Inversiones y cripto',
                        'rival' => 'Centrada en cuentas, tarjetas y presupuesto.',
                        'whisper' => 'Brokers y exchanges dentro del patrimonio neto: Indexa Capital, Interactive Brokers, Binance, Bitpanda, Coinbase y Wise.',
                    ],
                    [
                        'dimension' => 'Qué puedes comprobar',
                        'rival' => 'Código cerrado.',
                        'whisper' => 'Código público en GitHub.',
                    ],
                ],
                'narrative_title' => 'Lo que cuesta salir',
                'narrative' => [
                    'La comparación interesante con Wallet no es de funciones, porque las tiene casi todas y desde bastante antes que nosotros. Es de reversibilidad: qué te llevas si algún día decides irte.',
                    'Su propio centro de ayuda documenta el camino. En la web, sección Records, filtras, seleccionas los movimientos y eliges «Export to CSV» o «Export to xls». Ahí mismo indica que es una función premium y que lo que sale son los movimientos —fecha, importe, categoría y cuenta—, no tus presupuestos, ni tus objetivos, ni los pagos recurrentes que configuraste. Es decir: parte de lo que construiste durante años no viaja contigo.',
                    'La otra diferencia es el precio, que su web no publica. Saber lo que vas a pagar antes de instalar nada no es un detalle estético: es lo primero que quieres comparar cuando estás decidiendo.',
                    'Nada de esto convierte a Wallet en una mala aplicación. Son dos decisiones de producto distintas, y las dos se pueden comprobar hoy en sus respectivas páginas.',
                ],
                'bullets' => [
                    'Precio publicado en la web, sin instalar nada para descubrirlo.',
                    'Plan gratuito con la importación de extractos incluida, no detrás del plan de pago.',
                    'El fichero CSV o XLS que exportas de Wallet entra directamente, con mapeo de columnas y detección de duplicados.',
                    'El mapeo se guarda por cuenta: la segunda importación del mismo origen no requiere configurar nada.',
                    'Brokers, cripto y Wise integrados en el patrimonio neto.',
                    'Inmuebles y préstamos, para el patrimonio neto completo.',
                    'Reglas de automatización propias para reconstruir tus categorías en una sola pasada.',
                    'Categorización con IA opcional que aprende de tus correcciones, si das tu consentimiento.',
                    'Aplicación web completa, no solo móvil, e instalable como app en el teléfono.',
                    'Interfaz en español, inglés y francés, 33 divisas y código público en GitHub.',
                ],
                'migration_intro' => 'Wallet exporta a CSV y a XLS, que son exactamente dos de los formatos que el importador espera. La mudanza es un fichero por cuenta.',
                'migration_steps' => [
                    [
                        'title' => 'Exporta desde Wallet',
                        'body' => 'En su aplicación web entra en Records, filtra por cuenta y por el rango de fechas más amplio que tengas, pulsa «Select all» y usa el botón Export para elegir «Export to CSV» o «Export to xls». Repite la operación cuenta por cuenta: así cada fichero corresponde a una cuenta de destino aquí.',
                    ],
                    [
                        'title' => 'Crea o conecta la cuenta de destino',
                        'body' => 'Si tu banco conecta por PSD2, conéctalo y deja que traiga lo reciente. Si no, crea la cuenta a mano. El importador siempre pregunta a qué cuenta van los movimientos.',
                    ],
                    [
                        'title' => 'Sube el fichero',
                        'body' => 'En Transacciones abres el importador, seleccionas la cuenta y arrastras el .csv o el .xls que acabas de exportar. También acepta .xlsx.',
                    ],
                    [
                        'title' => 'Revisa el mapeo de columnas',
                        'body' => 'El importador propone las columnas por su cabecera. Confirmas fecha, descripción e importe, y puedes unir varias columnas en la descripción —por ejemplo la nota y el beneficiario— y elegir el formato de fecha entre cuatro opciones. La vista previa de tres filas te dice si acertó antes de continuar, y el mapeo se guarda para esa cuenta.',
                    ],
                    [
                        'title' => 'Recupera las categorías con reglas',
                        'body' => 'La exportación de Wallet trae la categoría de cada movimiento, pero no tus reglas. Al importar se aplican las reglas de automatización que hayas creado aquí, y lo que quede sin clasificar lo resuelve la IA si la activas. En la vista previa ves el total, los seleccionados y los duplicados antes de confirmar.',
                    ],
                ],
                'closing_body' => 'Si ya tienes el fichero exportado, la mudanza son cinco minutos por cuenta. Y si solo quieres probar antes de decidir, el plan gratuito incluye la importación y no pide tarjeta.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function monefy(): array
    {
        return [
            'testimonials' => [
                [
                    'name' => 'Haru',
                    'gravatar' => '3e52d6b2cbefb0fa2a572a588b3f7953',
                    'text' => [
                        'en' => 'I used to keep everything in a spreadsheet and it took forever — now I have my bank under control and add cash payments manually. Great work!',
                        'es' => 'Antes lo ponía todo en un Excel y me llevaba muchísimo tiempo; ahora tengo el banco controlado y los pagos en efectivo los añado manualmente. ¡Gran trabajo!',
                    ],
                ],
                [
                    'name' => 'Brian Bansuela',
                    'gravatar' => '9314f776a17ae977871076ac71f2ff60',
                    'text' => [
                        'en' => 'I just started syncing my accounts and it already feels like a great app. The interface is lovely and it looks like a really solid tool. Great work!',
                        'es' => 'Acabo de empezar a sincronizar cuentas y ya me parece una gran app. La interfaz me encanta y parece una herramienta muy sólida. ¡Muy buen trabajo!',
                    ],
                ],
            ],
            'en' => [
                'slug' => 'monefy-alternative',
                'rival' => 'Monefy',
                'title' => 'Monefy Alternative (2026) | Whisper Money',
                'description' => 'Monefy is extremely fast for logging expenses, but it does not connect to banks: "no bank login required", says its site. Whisper Money keeps the manual entry and adds bank connections and statement import.',
                'heading' => 'Monefy alternative',
                'intro' => 'Monefy is the fastest expense-logging app there is: open it, tap an icon, type the amount, done. It is among the highest-rated in Spain and its core is free, with an optional upgrade for unlimited accounts, recurring transactions and advanced filters. Its own site explains the trade-off plainly: "you can import statements manually or add expenses in seconds, no bank login required".',
                'rows' => [
                    [
                        'dimension' => 'Price',
                        'rival' => 'Free core plus an optional paid upgrade; its site does not publish the price of that upgrade (checked 18 August 2026).',
                        'whisper' => 'Price published, with a free plan and a paid plan in euros.',
                    ],
                    [
                        'dimension' => 'Where the transactions come from',
                        'rival' => 'You log them. It does not connect to banks, as a product decision: "no bank login required".',
                        'whisper' => 'The banks that connect over PSD2 come in on their own; everything else you log by hand or import.',
                    ],
                    [
                        'dimension' => 'Syncing between devices',
                        'rival' => 'Through your own Google Drive or Dropbox.',
                        'whisper' => 'The same account in the browser and on your phone, with no external storage in between.',
                    ],
                    [
                        'dimension' => 'Scope',
                        'rival' => 'Expenses and income split by category.',
                        'whisper' => 'Spending, budgets, cash flow and net worth, with brokers, crypto, property and loans.',
                    ],
                ],
                'narrative_title' => 'Logging fast is free; remembering to log is not',
                'narrative' => [
                    'Monefy\'s design is a good one, and its decision not to connect to banks is deliberate rather than a gap: it prioritises simplicity, speed and privacy, and says so on its site. For anyone living in cash, or anyone who wants to feel each expense as they record it, it works better than any aggregator.',
                    'The problem with manual logging is not the effort per entry, which is two seconds. It is coverage. Direct debits, subscriptions, the account maintenance fee and the purchase you made from your phone without thinking are precisely the expenses you do not log, and they are the ones that throw the month off. A manual history ends up being a history of what you remembered, which is not the same as what you spent.',
                    'Whisper Money keeps the manual part — you can log cash in two taps from your phone — and adds what the bank connection brings on its own. And if you would rather not connect the bank, the importer lets you upload the statement without handing credentials to anyone.',
                ],
                'bullets' => [
                    'Direct debits, subscriptions and fees come in by themselves: they do not depend on you remembering.',
                    'PSD2 connection is optional. If you do not want to connect the bank, you import the statement and that is that.',
                    'CSV, XLS and XLSX import with column mapping: the file Monefy exports goes straight in.',
                    'Logging cash by hand from your phone is still fast.',
                    'Category budgets on a weekly, biweekly, monthly or yearly period, with alerts.',
                    'Cash flow: income against spending month by month, not just how the spending splits.',
                    'Net worth with brokers, crypto, property and loans.',
                    'Automation rules and optional AI categorisation, if you consent to it.',
                    'Web and mobile on the same account, with no syncing through Drive or Dropbox.',
                    'Interface in English, Spanish and French, public code, and no data shared with third parties.',
                ],
                'migration_intro' => 'Monefy exports your data to CSV or Excel according to its own guide, and that is all it takes. Since Monefy does not split transactions by bank account, the usual approach is to import them all into a single cash account and carry on from there.',
                'migration_steps' => [
                    [
                        'title' => 'Export your records from Monefy',
                        'body' => 'Use its export-to-file feature and save the CSV somewhere you can reach from a computer: Drive, Dropbox or your own email all work.',
                    ],
                    [
                        'title' => 'Create the destination account',
                        'body' => 'Create a cash account, which is the closest match to what Monefy held. Banks, if you want them, get connected separately afterwards.',
                    ],
                    [
                        'title' => 'Upload the file',
                        'body' => 'Under Transactions, open the importer, pick that account and drag the CSV in. It also accepts .xls and .xlsx if you have been through a spreadsheet.',
                    ],
                    [
                        'title' => 'Confirm the column mapping',
                        'body' => 'You confirm the date column, the description column and the amount column. If the file carries expense and income separately, merge them beforehand into one column with expenses negative. You pick the date format from DD-MM-YYYY, YYYY-MM-DD, MM-DD-YYYY and YYYYMMDD and check the first three rows already parsed.',
                    ],
                    [
                        'title' => 'Import, then decide about the bank',
                        'body' => 'In the preview you see the total, the selected rows and the duplicates, unticked by default. On confirm your automation rules run. From then on you can keep logging by hand as you did in Monefy, connect the bank so it comes in on its own, or do both at once.',
                    ],
                ],
                'closing_body' => 'You can keep the best of Monefy — logging in two taps — and stop chasing the direct debits you never logged. The free plan asks for no card.',
            ],
            'es' => [
                'slug' => 'alternativa-a-monefy',
                'rival' => 'Monefy',
                'title' => 'Alternativa a Monefy (2026) | Whisper Money',
                'description' => 'Monefy es rapidísima para apuntar gastos, pero no conecta con bancos: «no bank login required», dice su web. Whisper Money conserva el apunte manual y añade la conexión bancaria y la importación de extractos.',
                'heading' => 'Alternativa a Monefy',
                'intro' => 'Monefy es la app de apuntar gastos más rápida que existe: abres, pulsas un icono, escribes el importe y ya está. Es de las más valoradas de España y su núcleo es gratuito, con una mejora opcional para cuentas ilimitadas, recurrentes y filtros avanzados. Su propia web explica la contrapartida sin rodeos: «you can import statements manually or add expenses in seconds, no bank login required».',
                'rows' => [
                    [
                        'dimension' => 'Precio',
                        'rival' => 'Núcleo gratuito y mejora opcional de pago; su web no publica el precio de esa mejora (consultado el 18 de agosto de 2026).',
                        'whisper' => 'Precio publicado, con plan gratuito y plan de pago en euros.',
                    ],
                    [
                        'dimension' => 'De dónde salen los movimientos',
                        'rival' => 'Los apuntas tú. No conecta con bancos, por decisión de producto: «no bank login required».',
                        'whisper' => 'Los bancos que conectan por PSD2 entran solos; lo demás se apunta a mano o se importa.',
                    ],
                    [
                        'dimension' => 'Sincronización entre dispositivos',
                        'rival' => 'A través de tu propio Google Drive o Dropbox.',
                        'whisper' => 'La misma cuenta en el navegador y en el móvil, sin depender de un almacenamiento externo.',
                    ],
                    [
                        'dimension' => 'Alcance',
                        'rival' => 'Gastos e ingresos repartidos por categoría.',
                        'whisper' => 'Gastos, presupuestos, flujo de caja y patrimonio neto, con brokers, cripto, inmuebles y préstamos.',
                    ],
                ],
                'narrative_title' => 'Apuntar rápido es gratis; acordarse de apuntar, no',
                'narrative' => [
                    'El diseño de Monefy es acertado, y su decisión de no conectar bancos es deliberada, no una carencia: prioriza simplicidad, velocidad y privacidad, y lo dice en su web. Para quien vive en efectivo, o para quien quiere sentir cada gasto en el momento de apuntarlo, funciona mejor que cualquier agregador.',
                    'El problema del registro manual no es el esfuerzo de cada apunte, que son dos segundos. Es la cobertura. Los recibos domiciliados, las suscripciones, la comisión de mantenimiento y la compra que hiciste desde el móvil sin pensar son precisamente los gastos que no apuntas, y son los que descuadran el mes. Un histórico manual acaba siendo el histórico de lo que recordaste, que no es lo mismo que el de lo que gastaste.',
                    'Whisper Money conserva la parte manual —puedes apuntar el efectivo en dos toques desde el móvil— y añade lo que la conexión bancaria trae sola. Y si prefieres no conectar el banco, como en Monefy, el importador te deja subir el extracto sin dar credenciales a nadie.',
                ],
                'bullets' => [
                    'Los recibos, las suscripciones y las comisiones entran solos: no dependen de que te acuerdes.',
                    'Conexión por PSD2 opcional. Si no quieres conectar el banco, importas el extracto y listo.',
                    'Importación de CSV, XLS y XLSX con mapeo de columnas: el fichero que exporta Monefy entra directo.',
                    'Sigue siendo rápido apuntar el efectivo a mano desde el móvil.',
                    'Presupuestos por categoría con periodo semanal, quincenal, mensual o anual, y avisos.',
                    'Flujo de caja: ingresos contra gastos mes a mes, no solo el reparto del gasto.',
                    'Patrimonio neto con brokers, cripto, inmuebles y préstamos.',
                    'Reglas de automatización y categorización con IA opcional, si das tu consentimiento.',
                    'Aplicación web y móvil con la misma cuenta, sin sincronizar a través de Drive ni Dropbox.',
                    'Interfaz en español, inglés y francés, código público y ningún dato compartido con terceros.',
                ],
                'migration_intro' => 'Monefy exporta tus datos a CSV o Excel, según su propia guía, y eso es todo lo que hace falta. Como en Monefy los movimientos no están separados por cuenta bancaria, lo habitual es importarlos todos a una única cuenta de efectivo y seguir desde ahí.',
                'migration_steps' => [
                    [
                        'title' => 'Exporta tus registros desde Monefy',
                        'body' => 'Usa su función de exportar a fichero y guarda el CSV donde puedas alcanzarlo desde el ordenador: Drive, Dropbox o tu propio correo sirven igual.',
                    ],
                    [
                        'title' => 'Crea la cuenta de destino',
                        'body' => 'Crea una cuenta de efectivo, que es la que mejor refleja lo que había en Monefy. Los bancos, si quieres tenerlos, los conectas después por separado.',
                    ],
                    [
                        'title' => 'Sube el fichero',
                        'body' => 'En Transacciones abres el importador, eliges esa cuenta y arrastras el CSV. También acepta .xls y .xlsx si lo has pasado por una hoja de cálculo.',
                    ],
                    [
                        'title' => 'Confirma el mapeo de columnas',
                        'body' => 'Confirmas la columna de fecha, la de concepto y la de importe. Si el fichero trae gasto e ingreso separados, únelos antes en una sola columna con los gastos en negativo. Eliges el formato de fecha entre DD-MM-YYYY, YYYY-MM-DD, MM-DD-YYYY y YYYYMMDD y compruebas las tres primeras filas ya interpretadas.',
                    ],
                    [
                        'title' => 'Importa y decide si conectas el banco',
                        'body' => 'En la vista previa ves el total, los seleccionados y los duplicados, desmarcados por defecto. Al confirmar se aplican tus reglas de automatización. Desde ese momento puedes seguir apuntando a mano como en Monefy, conectar el banco para que entre solo, o las dos cosas a la vez.',
                    ],
                ],
                'closing_body' => 'Puedes quedarte con lo mejor de Monefy —apuntar en dos toques— y dejar de perseguir los recibos que nunca apuntabas. El plan gratuito no pide tarjeta.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function margen(): array
    {
        return [
            'testimonials' => [
                [
                    'name' => 'Jorge Navarrete',
                    'gravatar' => 'd20d4e05a100d5b20b45c84f3c566a25',
                    'text' => [
                        'en' => "I'm exploring the web app and the design and UX are excellent. Thanks, team!",
                        'es' => 'Estoy descubriendo la aplicación web y, a nivel de diseño y UX, está excelente. ¡Gracias, chicos!',
                    ],
                ],
                [
                    'name' => 'Miguel Ángel SB',
                    'gravatar' => 'e5da1753042eee6b08a3db0df0b5f807',
                    'text' => [
                        'en' => 'I love the style, the intuitive interface, how easy it is to use. If it keeps growing like this, Whisper Money will be my finance app of choice. You can tell it is built with passion — and things made with passion can only end up a success.',
                        'es' => 'Me gusta mucho el estilo, la interfaz intuitiva, la facilidad de uso. Si sigue creciendo en esta línea, Whisper Money será mi futura aplicación de finanzas. Se nota que lo hacéis con pasión, y lo que se hace con pasión solo puede acabar siendo un éxito.',
                    ],
                ],
            ],
            'en' => [
                'slug' => 'margen-vs-whisper-money',
                'rival' => 'Margen',
                'title' => 'Margen vs Whisper Money (2026) | Comparison',
                'description' => 'Margen costs €4.99/month or €39.99/year and runs on iPhone only. Whisper Money is a web app installable on iPhone and Android, with PSD2, brokers, crypto and public code. Comparison and migration guide.',
                'heading' => 'Margen vs Whisper Money',
                'intro' => 'Margen launched in July 2026 with a clear, well-executed pitch: "all your money in a single number. Live." It solves the same problem we do — consolidated net worth — and connects BBVA, Santander, CaixaBank, Sabadell, ING, Trade Republic and Revolut. It is the most honest comparison we can draw, because we are nearly the same thing with two different decisions.',
                'rows' => [
                    [
                        'dimension' => 'Price',
                        'rival' => 'A free tier at €0/month and Premium at €4.99/month or €39.99/year (getmargen.com, 18 August 2026).',
                        'whisper' => 'A free plan and a paid plan in euros, with the rate published on the pricing page.',
                    ],
                    [
                        'dimension' => 'Where it runs',
                        'rival' => 'iPhone only: its site offers the App Store download and nothing else, with no web or Android version.',
                        'whisper' => 'A web app in any browser, installable as an app on iPhone and on Android.',
                    ],
                    [
                        'dimension' => 'What connects',
                        'rival' => 'BBVA, Santander, CaixaBank, Sabadell, ING, Trade Republic and Revolut, plus "AI import from any bank" on Premium.',
                        'whisper' => 'Spanish and European banks over PSD2, plus Indexa Capital, Interactive Brokers, Wise, Binance, Bitpanda and Coinbase.',
                    ],
                    [
                        'dimension' => 'What you can verify',
                        'rival' => 'Closed source.',
                        'whisper' => 'Public code on GitHub.',
                    ],
                ],
                'narrative_title' => 'Two different bets on where finances get looked at',
                'narrative' => [
                    'Margen bets on the phone and on the gesture of opening an app to see a number. It is well judged: net worth is a figure you consult rather than edit, and for consulting something the phone wins every time.',
                    'We bet the other way, for a specific reason. Reviewing categories, squaring a month, setting up budgets or working through a two-hundred-line statement is large-screen, keyboard work. Whisper Money is a web app that also installs on your phone, not a mobile app with a website beside it. If your relationship with your finances is checking the total once a day, the advantage is Margen\'s; if you also want to do something with that data, it changes sides.',
                    'The second difference is the reach of the aggregation. Margen publishes a specific list of institutions; we connect over PSD2 against the catalogue of Spanish and European banks, and add brokers and exchanges as our own integrations. If your money is at Indexa Capital, at Interactive Brokers or on a crypto exchange, that is where the difference shows.',
                    'And the third is structural: our code is public. You can read what the app does with your data before giving it access to your accounts, which is a rather firmer basis for trust than reading a privacy policy.',
                ],
                'bullets' => [
                    'Runs in a desktop browser, where two hundred transactions can actually be worked through.',
                    'Also installable on your phone, on both iPhone and Android.',
                    'PSD2 connections against the catalogue of Spanish and European banks, not a closed list of institutions.',
                    'Our own integrations with Indexa Capital, Interactive Brokers, Wise, Binance, Bitpanda and Coinbase.',
                    'Property and loans inside net worth.',
                    'Category budgets with alerts, on top of the net worth figure.',
                    'Monthly cash flow of income against spending.',
                    'Your own automation rules and optional AI categorisation.',
                    'CSV, XLS and XLSX import with column mapping and duplicate detection.',
                    'Public code on GitHub, price published on the site, and a permanent free plan.',
                ],
                'migration_intro' => 'Margen is recent and its site does not document a transaction export, so the reliable route does not go through Margen: it goes through the same institutions Margen connected for you. It is the same path you would take arriving from any aggregator.',
                'migration_steps' => [
                    [
                        'title' => 'Connect your banks over PSD2',
                        'body' => "You pick the institution and authorise access on the bank's own website. The sync pulls whatever history each institution hands over, and the accounts then keep themselves up to date without you stepping in again.",
                    ],
                    [
                        'title' => 'Add brokers and exchanges',
                        'body' => 'Indexa Capital, Interactive Brokers, Wise, Binance, Bitpanda and Coinbase connect with API keys you generate yourself at each service, and which you can create with read-only permissions. This is where net worth gets completed.',
                    ],
                    [
                        'title' => 'Download the long history from your bank',
                        'body' => 'For the years the connection does not bring, log in to online banking and export the transactions in CSV, XLS or XLSX: one download per account, over the widest range the institution allows.',
                    ],
                    [
                        'title' => 'Import it into the account already connected',
                        'body' => 'Under Transactions, open the importer, pick that same account and upload the file. Whatever the connection already brought shows up flagged as a duplicate and unticked, so nothing gets duplicated. Spanish headers are detected automatically and the mapping stays saved for that account.',
                    ],
                    [
                        'title' => 'Translate your categories into rules',
                        'body' => 'Before importing you see the total, the selected rows and the duplicates. On confirm your automation rules run: writing four or five for your regular merchants settles much of the history at once, and the AI takes care of the rest if you turn it on.',
                    ],
                ],
                'closing_body' => 'If Margen convinced you of the problem and what you are missing is the large screen, the brokers or code you can read, this is the same thing solved from the other side. The free plan asks for no card.',
            ],
            'es' => [
                'slug' => 'margen-vs-whisper-money',
                'rival' => 'Margen',
                'title' => 'Margen vs Whisper Money (2026) | Comparativa',
                'description' => 'Margen cuesta 4,99 €/mes o 39,99 €/año y funciona solo en iPhone. Whisper Money es una aplicación web instalable en iPhone y Android, con PSD2, brokers, cripto y código público. Comparativa y guía de migración.',
                'heading' => 'Margen vs Whisper Money',
                'intro' => 'Margen salió en julio de 2026 con una propuesta clara y bien ejecutada: «Todo tu dinero en un solo número. En vivo». Resuelve el mismo problema que nosotros —el patrimonio neto consolidado— y conecta BBVA, Santander, CaixaBank, Sabadell, ING, Trade Republic y Revolut. Es la comparación más honesta que podemos hacer, porque somos casi lo mismo con dos decisiones distintas.',
                'rows' => [
                    [
                        'dimension' => 'Precio',
                        'rival' => 'Plan gratuito de 0 €/mes y Premium a 4,99 €/mes o 39,99 €/año (getmargen.com, 18 de agosto de 2026).',
                        'whisper' => 'Plan gratuito y plan de pago en euros, con la tarifa publicada en la página de precios.',
                    ],
                    [
                        'dimension' => 'Dónde funciona',
                        'rival' => 'Solo iPhone: su web ofrece únicamente la descarga en App Store, sin versión web ni Android.',
                        'whisper' => 'Aplicación web en cualquier navegador, instalable como app en iPhone y en Android.',
                    ],
                    [
                        'dimension' => 'Qué se conecta',
                        'rival' => 'BBVA, Santander, CaixaBank, Sabadell, ING, Trade Republic y Revolut, más «importación con IA de cualquier banco» en Premium.',
                        'whisper' => 'Bancos españoles y europeos por PSD2, más Indexa Capital, Interactive Brokers, Wise, Binance, Bitpanda y Coinbase.',
                    ],
                    [
                        'dimension' => 'Qué puedes comprobar',
                        'rival' => 'Código cerrado.',
                        'whisper' => 'Código público en GitHub.',
                    ],
                ],
                'narrative_title' => 'Dos apuestas distintas sobre dónde se miran las finanzas',
                'narrative' => [
                    'Margen apuesta por el móvil y por el gesto de abrir la app para ver un número. Está bien pensado: el patrimonio neto es un dato que se consulta, no que se edita, y para consultar algo el móvil gana siempre.',
                    'Nosotros apostamos por lo contrario, y por un motivo concreto. Revisar categorías, cuadrar un mes, montar presupuestos o repasar un extracto de doscientas líneas es trabajo de pantalla grande y teclado. Whisper Money es una aplicación web que además se instala en el móvil, no una app móvil con una web al lado. Si tu relación con tus finanzas consiste en mirar el total una vez al día, la ventaja es de Margen; si además quieres hacer algo con esos datos, cambia de lado.',
                    'La segunda diferencia es el alcance de la agregación. Margen publica una lista concreta de entidades; nosotros conectamos por PSD2 contra el catálogo de bancos españoles y europeos, y añadimos brokers y exchanges como integraciones propias. Si tu dinero está en Indexa Capital, en Interactive Brokers o en un exchange de cripto, ahí es donde se nota la diferencia.',
                    'Y la tercera es de fondo: nuestro código es público. Puedes leer qué hace la aplicación con tus datos antes de darle acceso a tus cuentas, lo cual es una forma bastante más sólida de confiar que leerse una política de privacidad.',
                ],
                'bullets' => [
                    'Funciona en el navegador del ordenador, donde se puede trabajar de verdad con doscientas transacciones.',
                    'Instalable también en el móvil, tanto en iPhone como en Android.',
                    'Conexión PSD2 contra el catálogo de bancos españoles y europeos, no una lista cerrada de entidades.',
                    'Integraciones propias con Indexa Capital, Interactive Brokers, Wise, Binance, Bitpanda y Coinbase.',
                    'Inmuebles y préstamos dentro del patrimonio neto.',
                    'Presupuestos por categoría con avisos, además del patrimonio neto.',
                    'Flujo de caja mensual de ingresos contra gastos.',
                    'Reglas de automatización propias y categorización con IA opcional.',
                    'Importación de CSV, XLS y XLSX con mapeo de columnas y detección de duplicados.',
                    'Código público en GitHub, precio publicado en la web y plan gratuito permanente.',
                ],
                'migration_intro' => 'Margen es reciente y su web no documenta una exportación de movimientos, así que la ruta fiable no pasa por Margen: pasa por las mismas entidades que Margen te conectó. Es el mismo camino que harías al llegar de cualquier agregador.',
                'migration_steps' => [
                    [
                        'title' => 'Conecta tus bancos por PSD2',
                        'body' => 'Eliges la entidad y autorizas el acceso en la web del propio banco. La sincronización trae el histórico que cada entidad entregue y las cuentas quedan actualizándose solas, sin que tengas que volver a intervenir.',
                    ],
                    [
                        'title' => 'Añade brokers y exchanges',
                        'body' => 'Indexa Capital, Interactive Brokers, Wise, Binance, Bitpanda y Coinbase se conectan con claves de API que generas tú en cada servicio, y que puedes crear con permisos de solo lectura. Aquí es donde el patrimonio neto se completa.',
                    ],
                    [
                        'title' => 'Descarga el histórico largo de tu banco',
                        'body' => 'Para los años que la conexión no traiga, entra en la banca online y exporta los movimientos en CSV, XLS o XLSX: una descarga por cuenta, con el rango más amplio que la entidad te permita.',
                    ],
                    [
                        'title' => 'Impórtalo a la cuenta ya conectada',
                        'body' => 'En Transacciones abres el importador, eliges esa misma cuenta y subes el fichero. Lo que la conexión ya trajo aparece marcado como duplicado y desmarcado, así que no se duplica nada. Las cabeceras españolas se detectan solas y el mapeo queda guardado para esa cuenta.',
                    ],
                    [
                        'title' => 'Traduce tus categorías a reglas',
                        'body' => 'Antes de importar ves el total, los seleccionados y los duplicados. Al confirmar se aplican tus reglas de automatización: crear cuatro o cinco para tus comercios habituales resuelve buena parte del histórico de golpe, y la IA se ocupa del resto si la activas.',
                    ],
                ],
                'closing_body' => 'Si Margen te convenció del problema y te falta la pantalla grande, los brokers o el código que se puede leer, esto es lo mismo resuelto por el otro lado. El plan gratuito no pide tarjeta.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function dinerio(): array
    {
        return [
            'testimonials' => [
                [
                    'name' => 'Tom',
                    'gravatar' => 'd721bb1875ac11132d4d33295867cbd9',
                    'text' => [
                        'en' => "I'm genuinely happy using an open-source project with a real commitment to privacy — that's exactly what I want from a finance app.",
                        'es' => 'Estoy realmente contento usando un proyecto open-source con un compromiso real con la privacidad: es exactamente lo que quiero de una app de finanzas.',
                    ],
                ],
                [
                    'name' => 'Ricardo Rovira',
                    'text' => [
                        'en' => "I really like the app — it's exactly what I was looking for. It's a great project, and I think you've nailed what a personal finance app should be, at least the way I want to manage mine.",
                        'es' => 'La aplicación me gusta mucho, es justo lo que buscaba. Me parece muy chulo el proyecto que habéis realizado y creo que habéis dado con la tecla con lo que debe ser una aplicación de gestión de finanzas personales, por lo menos como lo quiero gestionar yo.',
                    ],
                ],
            ],
            'en' => [
                'slug' => 'dinerio-vs-whisper-money',
                'rival' => 'Dinerio',
                'title' => 'Dinerio vs Whisper Money (2026) | Comparison',
                'description' => 'Dinerio costs €3.99/month or €35.88/year and does not connect to banks by design. Whisper Money connects over PSD2 without ever asking for credentials, has a permanent free plan, and the code is public.',
                'heading' => 'Dinerio vs Whisper Money',
                'intro' => 'Dinerio is a Spanish personal finance manager that costs €3.99/month or €35.88/year, with a 14-day trial, and it has made an explicit design decision: "Dinerio does not connect to bank accounts and does not ask for credentials, so your data is yours alone". It defends privacy with the same argument we do, but arrives at it from the opposite direction.',
                'rows' => [
                    [
                        'dimension' => 'Price',
                        'rival' => '€3.99/month or €35.88/year (equivalent to €2.99/month), with a 14-day trial (dinerioapp.es, 18 August 2026).',
                        'whisper' => 'A permanent free plan and a paid plan in euros, with the rate published on the site.',
                    ],
                    [
                        'dimension' => 'Bank connection',
                        'rival' => 'Does not connect to banks, by design. You enter the transactions yourself.',
                        'whisper' => 'PSD2 connection is optional. If you would rather not connect, you import the statement and work exactly as you would in Dinerio.',
                    ],
                    [
                        'dimension' => 'Credentials',
                        'rival' => 'Never asked for, because there is no connection.',
                        'whisper' => "Never asked for either: the PSD2 authorisation is signed on your bank's website and all we receive is a revocable read permission.",
                    ],
                    [
                        'dimension' => 'What you can verify',
                        'rival' => 'Closed source. States that data is encrypted on European servers (Supabase, Frankfurt).',
                        'whisper' => 'Public code on GitHub: the privacy promise can be read, not just believed.',
                    ],
                ],
                'narrative_title' => 'Two ways of never touching your credentials',
                'narrative' => [
                    'Dinerio\'s argument is coherent and deserves explaining properly: if the app never connects to your bank, there are no credentials to leak and no permission to revoke. It is a legitimate way to solve the problem, and the price of that peace of mind is that the user types everything.',
                    'One thing is worth clearing up, because it is the most common confusion in this market: connecting your bank over PSD2 does not mean giving your keys to anybody. PSD2 is the European open banking regulation. The authorisation is signed on your own bank\'s website, with your own authentication factors, and what the application receives is a read permission — temporary, and revocable from the bank whenever you like. We never see or store your username or your password at any point.',
                    'So the real difference is not privacy against convenience: it is who does the work of entering the data. In Dinerio you always do. Here you choose: connect it and it comes in on its own, or do not connect and import the statement, which is the same data you would be feeding Dinerio by hand, without typing it line by line.',
                    'And on which of the two is more verifiable, the answer applies to both: look at the code. Ours is public.',
                ],
                'bullets' => [
                    'A permanent free plan, not just a 14-day trial.',
                    'You can work without connecting any bank, exactly as in Dinerio, and still load years of statements in minutes.',
                    "If you do decide to connect, the authorisation is signed on your bank's website and revoked from there.",
                    'CSV, XLS and XLSX import with column mapping, duplicate detection and per-account saved mapping.',
                    'Brokers and exchanges integrated: Indexa Capital, Interactive Brokers, Wise, Binance, Bitpanda and Coinbase.',
                    'Property and loans, for the complete net worth figure.',
                    'Category budgets, cash flow and net worth in the same application.',
                    'Automation rules and optional AI categorisation that learns from your corrections.',
                    'Interface in English, Spanish and French, across 33 currencies.',
                    'Public code on GitHub and no data shared with third parties.',
                ],
                'migration_intro' => "Dinerio's site does not document a transaction export, so the reliable route is the same one you used to feed Dinerio: your bank statement. The difference is that here you upload it instead of typing it.",
                'migration_steps' => [
                    [
                        'title' => 'Create your account and decide whether to connect',
                        'body' => "If you want to carry on without a bank connection, create the accounts by hand and skip to step three. If you would rather it came in on its own, connect the institution by authorising access on the bank's website; you can revoke it there whenever you want.",
                    ],
                    [
                        'title' => 'Check what the connection brings',
                        'body' => 'The first sync downloads whatever history your institution hands over and leaves the account updating itself. Whatever is missing gets filled in by importing, and you do not have to decide that now: you can import later.',
                    ],
                    [
                        'title' => 'Download your statements',
                        'body' => 'In each account\'s online banking, export the transactions over the widest period it allows, in CSV, XLS or XLSX. If you were keeping the books by hand and have that history in a spreadsheet, that works as it is too.',
                    ],
                    [
                        'title' => 'Upload the file and confirm the mapping',
                        'body' => 'You pick the destination account and drag the file in. Spanish banking headers are detected automatically: you confirm date, description and amount, and optionally balance, creditor and debtor. You pick the date format from four options and see the first three rows already parsed. The mapping is saved for that account.',
                    ],
                    [
                        'title' => 'Check duplicates and confirm',
                        'body' => 'The preview gives you the total, the selected rows and the duplicates, matched against what is already in the account and unticked by default. On import your automation rules run, and the AI categorises whatever is left if you have turned it on.',
                    ],
                ],
                'closing_body' => "If Dinerio's privacy argument convinced you, here it is with two additions: code you can read, and the option of letting the transactions come in on their own whenever you feel like it. The free plan asks for no card.",
            ],
            'es' => [
                'slug' => 'dinerio-vs-whisper-money',
                'rival' => 'Dinerio',
                'title' => 'Dinerio vs Whisper Money (2026) | Comparativa',
                'description' => 'Dinerio cuesta 3,99 €/mes o 35,88 €/año y no conecta con bancos por diseño. Whisper Money conecta por PSD2 sin pedirte credenciales, tiene plan gratuito permanente y el código es público. Comparativa y guía de migración.',
                'heading' => 'Dinerio vs Whisper Money',
                'intro' => 'Dinerio es un gestor español de finanzas personales que cuesta 3,99 €/mes o 35,88 €/año, con 14 días de prueba, y que ha tomado una decisión de diseño explícita: «Dinerio no se conecta a cuentas bancarias ni pide credenciales, así que tus datos son solo tuyos». Defiende la privacidad con el mismo argumento que nosotros, pero llegando por el camino opuesto.',
                'rows' => [
                    [
                        'dimension' => 'Precio',
                        'rival' => '3,99 €/mes o 35,88 €/año (equivalente a 2,99 €/mes), con 14 días de prueba (dinerioapp.es, 18 de agosto de 2026).',
                        'whisper' => 'Plan gratuito permanente y plan de pago en euros, con la tarifa publicada en la web.',
                    ],
                    [
                        'dimension' => 'Conexión bancaria',
                        'rival' => 'No conecta con bancos, por diseño. Los movimientos los introduces tú.',
                        'whisper' => 'Conexión por PSD2 opcional. Si prefieres no conectar, importas el extracto y trabajas igual que en Dinerio.',
                    ],
                    [
                        'dimension' => 'Credenciales',
                        'rival' => 'No las pide, porque no hay conexión.',
                        'whisper' => 'No las pedimos tampoco: la autorización PSD2 se firma en la web de tu banco y nosotros solo recibimos un permiso de lectura revocable.',
                    ],
                    [
                        'dimension' => 'Qué puedes comprobar',
                        'rival' => 'Código cerrado. Declara datos cifrados en servidores europeos (Supabase, Frankfurt).',
                        'whisper' => 'Código público en GitHub: la promesa de privacidad se puede leer, no solo creer.',
                    ],
                ],
                'narrative_title' => 'Dos maneras de no tocar tus credenciales',
                'narrative' => [
                    'El argumento de Dinerio es coherente y merece explicarse bien: si la app nunca conecta con tu banco, no hay credenciales que filtrar ni permiso que revocar. Es una forma legítima de resolver el problema, y el precio de esa tranquilidad es que todo lo teclea el usuario.',
                    'Conviene aclarar algo, porque es la confusión más común de este mercado: conectar el banco por PSD2 no significa dar tus claves a nadie. PSD2 es la normativa europea de banca abierta. La autorización se firma en la web de tu propio banco, con tus factores de autenticación, y lo que la aplicación recibe es un permiso de lectura, temporal y revocable desde el banco cuando quieras. Nosotros no vemos ni almacenamos tu usuario ni tu contraseña en ningún momento.',
                    'Así que la diferencia real no es privacidad contra comodidad: es quién hace el trabajo de meter los datos. En Dinerio lo haces siempre tú. Aquí eliges: conectas y entra solo, o no conectas e importas el extracto, que es exactamente el mismo dato que alimentarías a mano en Dinerio pero sin teclearlo línea a línea.',
                    'Y sobre quién es más comprobable, la respuesta vale para las dos: mira el código. El nuestro es público.',
                ],
                'bullets' => [
                    'Plan gratuito permanente, no solo 14 días de prueba.',
                    'Puedes trabajar sin conectar ningún banco, igual que en Dinerio, y aun así cargar años de extractos en minutos.',
                    'Si decides conectar, la autorización se firma en la web de tu banco y se revoca desde allí.',
                    'Importación de CSV, XLS y XLSX con mapeo de columnas, detección de duplicados y mapeo guardado por cuenta.',
                    'Brokers y exchanges integrados: Indexa Capital, Interactive Brokers, Wise, Binance, Bitpanda y Coinbase.',
                    'Inmuebles y préstamos, para el patrimonio neto completo.',
                    'Presupuestos por categoría, flujo de caja y patrimonio neto en la misma aplicación.',
                    'Reglas de automatización y categorización con IA opcional que aprende de tus correcciones.',
                    'Interfaz en español, inglés y francés, con 33 divisas.',
                    'Código público en GitHub y ningún dato compartido con terceros.',
                ],
                'migration_intro' => 'La web de Dinerio no documenta una exportación de movimientos, así que la vía fiable es la misma con la que alimentabas Dinerio: el extracto de tu banco. La diferencia es que aquí lo subes en lugar de teclearlo.',
                'migration_steps' => [
                    [
                        'title' => 'Crea tu cuenta y decide si conectas',
                        'body' => 'Si quieres seguir sin conexión bancaria, crea las cuentas a mano y salta al paso tres. Si prefieres que entre solo, conecta la entidad autorizando el acceso en la web del banco; puedes revocarlo desde allí cuando quieras.',
                    ],
                    [
                        'title' => 'Comprueba qué trae la conexión',
                        'body' => 'La primera sincronización descarga el histórico que tu entidad entregue y deja la cuenta actualizándose sola. Lo que falte se completa importando, y no hace falta que decidas esto ahora: puedes importar más tarde.',
                    ],
                    [
                        'title' => 'Descarga tus extractos',
                        'body' => 'En la banca online de cada cuenta exporta los movimientos al periodo más amplio que te permita, en CSV, XLS o XLSX. Si llevabas la contabilidad a mano y tienes ese histórico en una hoja de cálculo, también sirve tal cual.',
                    ],
                    [
                        'title' => 'Sube el fichero y confirma el mapeo',
                        'body' => 'Eliges la cuenta de destino y arrastras el fichero. Las cabeceras de la banca española se detectan automáticamente: confirmas fecha, descripción e importe, y de forma opcional el saldo, el ordenante y el beneficiario. Eliges el formato de fecha entre cuatro opciones y ves las tres primeras filas ya interpretadas. El mapeo se guarda para esa cuenta.',
                    ],
                    [
                        'title' => 'Revisa duplicados y confirma',
                        'body' => 'La vista previa te da el total, los seleccionados y los duplicados, comparados con lo que ya hay en la cuenta y desmarcados por defecto. Al importar se aplican tus reglas de automatización, y la IA categoriza lo que quede suelto si la has activado.',
                    ],
                ],
                'closing_body' => 'Si te convenció el argumento de privacidad de Dinerio, aquí lo tienes con dos añadidos: el código que puedes leer y la opción de que los movimientos entren solos cuando te apetezca. El plan gratuito no pide tarjeta.',
            ],
        ];
    }
}
