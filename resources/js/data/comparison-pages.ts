/**
 * Copy for the public comparison landing pages.
 *
 * It is Spanish-only on purpose: these pages target the Spanish market, are not
 * part of the translated product UI, and would otherwise force eight long
 * translations of marketing prose into every locale file.
 *
 * Every claim about a competitor is a fact published on its own pages and was
 * verified on 2026-08-18. Re-check the prices before touching a page and update
 * the date quoted inside the row. Testimonials are real quotes taken from the
 * landing page pool; a slot with no real quote yet uses `pending`, which only
 * renders outside production.
 */

export type ComparisonRow = {
    dimension: string;
    rival: string;
    whisper: string;
};

export type ComparisonStep = {
    title: string;
    body: string;
};

export type ComparisonTestimonial =
    | { name: string; gravatar?: string; avatar?: string; text: string }
    | { pending: string };

export type ComparisonPage = {
    /** Last path segment, also the key the controller validates against. */
    slug: string;
    /** Reads inside "migrar de X" and "migraron desde X". */
    rival: string;
    title: string;
    description: string;
    heading: string;
    intro: string;
    rows: ComparisonRow[];
    narrativeTitle: string;
    narrative: string[];
    bullets: string[];
    migrationIntro: string;
    migrationSteps: ComparisonStep[];
    testimonials: ComparisonTestimonial[];
    closingBody: string;
};

const fintonic: ComparisonPage = {
    slug: 'alternativa-a-fintonic',
    rival: 'Fintonic',
    title: 'Alternativa a Fintonic (2026) | Whisper Money',
    description:
        'Fintonic es gratis porque gana dinero intermediando préstamos y seguros. Whisper Money se paga con una suscripción, agrega tus bancos por PSD2 y suma inversiones y cripto. Comparativa y guía de migración.',
    heading: 'Alternativa a Fintonic',
    intro: 'Fintonic fue la app que acostumbró a España a mirar sus cuentas desde el móvil. Es gratuita, está inscrita en el registro del Banco de España y agrega bancos por PSD2. Lo que conviene mirar antes de quedarse es de dónde sale su dinero: su propia web explica que intermedia préstamos con más de cuarenta entidades, y también seguros y cuentas remuneradas.',
    rows: [
        {
            dimension: 'Precio y modelo de negocio',
            rival: 'Gratis. Los ingresos vienen de intermediar préstamos, seguros y cuentas remuneradas: «intermediamos por ti con más de 40 entidades», dice su web (consultado el 18 de agosto de 2026).',
            whisper:
                'Plan gratuito y plan de pago por suscripción. No intermediamos ningún producto financiero, así que nadie nos paga por lo que aparece en tu pantalla.',
        },
        {
            dimension: 'Qué hay dentro de la app',
            rival: 'Además del análisis de gastos, ofertas de préstamos, seguros y cuentas remuneradas.',
            whisper:
                'Solo tus cuentas y tus números. Ninguna oferta de terceros.',
        },
        {
            dimension: 'Qué puedes agregar',
            rival: 'Cuentas y tarjetas de bancos españoles.',
            whisper:
                'Bancos por PSD2, más brokers (Indexa Capital, Interactive Brokers), cripto (Binance, Bitpanda, Coinbase) y Wise, todo dentro del mismo patrimonio neto.',
        },
        {
            dimension: 'Qué puedes comprobar',
            rival: 'Código cerrado: su política de privacidad es lo que hay.',
            whisper:
                'El código es público en GitHub, así que puedes leer qué hace la aplicación con tus datos en lugar de creerte una promesa.',
        },
    ],
    narrativeTitle:
        'La letra pequeña: una app gratis tiene que cobrar a alguien',
    narrative: [
        'Que Fintonic sea gratis no es un truco, es una decisión de modelo de negocio, y está declarada en su propia web. Analiza tu perfil, lo compara con la oferta de más de cuarenta entidades y cobra a la entidad cuando el préstamo o el seguro sale adelante. Para ti no tiene coste. Para la app, tu historial de gastos es la materia prima con la que decide qué producto te encaja.',
        'Eso cambia lo que la aplicación optimiza. Una app que vive de la intermediación tiene un motivo para conocer tu capacidad de endeudamiento; una que vive de una suscripción solo tiene un motivo para que sigas usándola el mes que viene. Quien paga marca la prioridad.',
        'Whisper Money cobra una suscripción y tiene un plan gratuito sin publicidad. No hay comparador de préstamos, ni seguros, ni cuentas remuneradas, ni comisiones de nadie por lo que ves. Si algún día deja de resultarte útil, lo cancelas, y ese es todo el incentivo que tenemos.',
    ],
    bullets: [
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
    migrationIntro:
        'Fintonic muestra los movimientos que le entrega tu banco, así que el origen fiable de tu histórico es el banco, no la app. El importador de Whisper Money está pensado justo para eso: coges el fichero del banco y él se encarga de entender las columnas.',
    migrationSteps: [
        {
            title: 'Crea la cuenta y conecta tus bancos',
            body: 'En el alta eliges tu entidad y autorizas el acceso por PSD2 en la web del propio banco, igual que en Fintonic. Nosotros no vemos ni guardamos tus credenciales. La primera sincronización trae el histórico que tu banco entregue, que varía de una entidad a otra; si quieres más años, sigue con el paso siguiente.',
        },
        {
            title: 'Descarga tu histórico del banco',
            body: 'Entra en la banca online de cada cuenta y exporta los movimientos al periodo más amplio que te permita, en CSV, XLS o XLSX. Los ficheros de la banca española traen casi siempre las columnas Fecha, F. Valor, Concepto, Importe y Saldo. No hace falta tocarlas.',
        },
        {
            title: 'Abre el importador y elige la cuenta de destino',
            body: 'En Transacciones abres el importador y seleccionas a qué cuenta van esos movimientos. Después arrastras el fichero o lo buscas: acepta .csv, .xls y .xlsx.',
        },
        {
            title: 'Confirma el mapeo de columnas',
            body: 'El importador reconoce solo las cabeceras en español (Fecha, F. Valor, Concepto, Descripción, Importe, Saldo) y te propone el mapeo. Tú confirmas fecha, descripción e importe, y de forma opcional el saldo, el ordenante y el beneficiario. Puedes unir varias columnas en la descripción y elegir el formato de fecha entre DD-MM-YYYY, YYYY-MM-DD, MM-DD-YYYY y YYYYMMDD, con una vista previa de las tres primeras filas. El mapeo se guarda para esa cuenta, así que el siguiente fichero del mismo banco entra sin tocar nada.',
        },
        {
            title: 'Revisa los duplicados y confirma',
            body: 'Antes de importar ves el total, cuántos movimientos están seleccionados y cuántos son duplicados. Los duplicados se comparan con lo que ya hay en la cuenta y vienen desmarcados, así que puedes repetir la importación de un mes sin miedo. Al confirmar se aplican tus reglas de automatización y, si has activado la IA, categoriza lo que quede suelto.',
        },
    ],
    testimonials: [
        {
            name: 'Marcus Oliveira',
            gravatar: '3c4342baddf0beb8b0bd9fe89168e282',
            text: 'Gracias por desarrollar Whisper Money. El enfoque en la privacidad y en centralizar las finanzas es una propuesta excelente.',
        },
        {
            name: 'Marta Bordiu',
            gravatar: '8329bd04eb4272db2e94f7c849ca7776',
            text: 'Llevaba tiempo sin dar el paso con las apps de finanzas porque me preocupaban mis datos; esta es la primera en la que confié lo suficiente como para pasarme a premium. Me encanta poder tenerlo todo en un solo sitio, inversiones incluidas.',
        },
        {
            pending:
                'TODO: testimonio real pendiente de un usuario que venía de Fintonic',
        },
    ],
    closingBody:
        'Ya hay gente gestionando aquí lo que antes miraba en Fintonic: las mismas cuentas, sin ofertas de préstamos por medio y con las inversiones dentro del total. El plan gratuito no pide tarjeta.',
};

const ynab: ComparisonPage = {
    slug: 'alternativa-a-ynab-en-espanol',
    rival: 'YNAB',
    title: 'Alternativa a YNAB en español (2026) | Whisper Money',
    description:
        'YNAB cuesta 14,99 $/mes o 109 $/año y factura en dólares, con importación directa limitada a algunos bancos. Whisper Money está en español, conecta bancos por PSD2 e importa ficheros. Comparativa y guía de migración.',
    heading: 'Alternativa a YNAB en español',
    intro: 'YNAB (You Need A Budget) es probablemente el método de presupuesto con más devotos del mundo, y con razón: asignar cada euro a una categoría antes de gastarlo funciona. El problema en España no es el método, es la logística. El precio está en dólares, la aplicación y su documentación están en inglés y la importación automática solo cubre parte de los bancos europeos.',
    rows: [
        {
            dimension: 'Precio',
            rival: '14,99 $/mes o 109 $/año. Su página de precios avisa de que la tarifa está «priced in US dollars» y no publica precio en euros (consultado el 18 de agosto de 2026).',
            whisper:
                'Plan gratuito y plan de pago facturado en euros, con la tarifa publicada en la página de precios.',
        },
        {
            dimension: 'Idioma',
            rival: 'Aplicación, web comercial y centro de ayuda en inglés.',
            whisper: 'Interfaz en español, inglés y francés.',
        },
        {
            dimension: 'Conexión bancaria',
            rival: '«Direct import currently supports select US, Canadian, UK, and EU Banks». Para el resto, importación de fichero.',
            whisper:
                'Conexión por PSD2 con bancos españoles y europeos, e importación de fichero cuando la entidad no conecta.',
        },
        {
            dimension: 'Método de presupuesto',
            rival: 'Sobres: el saldo disponible se asigna entero antes de gastar.',
            whisper:
                'Presupuestos por categoría —semanales, quincenales, mensuales o anuales— con arrastre opcional del sobrante. Puedes presupuestar solo lo que te interese.',
        },
    ],
    narrativeTitle:
        'La trampa: el método viaja, teclear los datos no hace falta que viaje',
    narrative: [
        'La suscripción de YNAB son 109 $ al año facturados en dólares, así que lo que acabas pagando depende del cambio del día y de lo que tu banco cobre por operar en divisa. Esa es la parte visible.',
        'La menos visible es el trabajo diario. Su propia página lo dice: la importación directa cubre «select US, Canadian, UK, and EU Banks». Si tu entidad no está en ese grupo, la rutina consiste en descargar el extracto y subirlo a mano cada semana, con lo que la herramienta que compraste para ahorrarte tiempo te lo cobra dos veces, en dólares y en domingos.',
        'La buena noticia es que lo que aprendiste en YNAB no se pierde al cambiar de app. Poner límites por categoría, revisar el mes y decidir antes de gastar son hábitos, no funciones de un producto concreto. Whisper Money los sostiene con presupuestos por categoría y arrastre del sobrante, en tu idioma y con el banco sincronizándose solo.',
    ],
    bullets: [
        'Conexión por PSD2 con bancos españoles y europeos, sin subir un fichero cada semana.',
        'Interfaz en español, sin traducir mentalmente «payee», «inflow» o «to be budgeted».',
        'Tarifa en euros, sin comisión de cambio de divisa en tu tarjeta.',
        'Presupuestos por categoría con periodo semanal, quincenal, mensual o anual.',
        'Arrastre del sobrante al periodo siguiente cuando lo quieras, sin obligarte a cuadrar cada euro.',
        'Un presupuesto que recoge lo que no encaja en ningún otro, para que no se te escape gasto sin clasificar.',
        'Reglas de automatización para categorizar por comercio, importe o texto, sin repetir el trabajo cada mes.',
        'Categorización con IA opcional que aprende de tus correcciones, si das tu consentimiento.',
        'Inversiones y cripto en el mismo patrimonio neto: Indexa Capital, Interactive Brokers, Wise, Binance, Bitpanda y Coinbase.',
        'Importación de CSV, XLS y XLSX con mapeo de columnas para las cuentas que no conectan.',
    ],
    migrationIntro:
        'YNAB exporta tus datos, así que la mudanza es directa. En su aplicación web pulsas el nombre del plan en la barra lateral izquierda y eliges «Export Plan»: obtienes dos ficheros, uno con el plan y las categorías y otro con el histórico de movimientos. El segundo es el que vamos a usar. También puedes seleccionar los movimientos de una cuenta y exportar solo esos.',
    migrationSteps: [
        {
            title: 'Crea la cuenta y prepara las cuentas de destino',
            body: 'Conecta por PSD2 los bancos que sincronicen y crea a mano los que no. Necesitas la cuenta de destino creada antes de importar, porque el importador siempre pregunta a dónde van los movimientos.',
        },
        {
            title: 'Exporta desde YNAB',
            body: 'En la web de YNAB, nombre del plan en la barra lateral izquierda y «Export Plan». Descargas el histórico de movimientos en CSV, o en TSV si tu moneda usa la coma como decimal. Si te sale en TSV, guárdalo como CSV desde tu hoja de cálculo antes de subirlo.',
        },
        {
            title: 'Une Outflow e Inflow en una sola columna',
            body: 'YNAB reparte el importe en dos columnas, Outflow e Inflow. Nuestro importador espera una sola: en tu hoja de cálculo crea una columna de importe con los gastos en negativo y los ingresos en positivo. Es la única edición manual de toda la mudanza.',
        },
        {
            title: 'Sube el fichero y mapea las columnas',
            body: 'En Transacciones abres el importador, eliges la cuenta y arrastras el fichero. El fichero de YNAB trae Date, Payee, Memo y Category: asignas Date a la fecha, Payee a la descripción —puedes añadir Memo como segunda columna y se unen— y tu columna nueva al importe. El formato de fecha lo eliges entre DD-MM-YYYY, YYYY-MM-DD, MM-DD-YYYY y YYYYMMDD, y ves las tres primeras filas ya interpretadas antes de continuar.',
        },
        {
            title: 'Convierte tus categorías en reglas e importa',
            body: 'La vista previa te da el total, los seleccionados y los duplicados, que vienen desmarcados. Al importar se aplican tus reglas de automatización, así que crear cuatro o cinco reglas para tus comercios habituales recoloca buena parte del histórico de una pasada; lo que quede sin categoría lo resuelve la IA si la activas.',
        },
    ],
    testimonials: [
        {
            name: 'Priya Nair',
            gravatar: '299c92b453769c8805a14f3044157f22',
            text: 'Categorizar las transacciones me comía el domingo entero. Ahora lo hace la IA y acierta en casi todas, y las pocas que falla las recuerda en cuanto las corrijo una vez. Ya casi ni lo toco.',
        },
        {
            name: 'Sofía Romero',
            gravatar: '51bd48ebe85a4f936b1f2ac38ee39238',
            text: 'Mis cuentas se sincronizan solas y todo llega ya ordenado, así que revisar mi presupuesto me lleva dos minutos en vez de una hora. No pensé que fuese a mantenerlo al día, pero lo he conseguido.',
        },
        {
            pending:
                'TODO: testimonio real pendiente de un usuario que venía de YNAB',
        },
    ],
    closingBody:
        'El método que aprendiste en YNAB sigue valiendo. Lo que cambia es que aquí lo aplicas en euros, en español y con el banco entrando solo. El plan gratuito no pide tarjeta.',
};

const spreadsheets: ComparisonPage = {
    slug: 'alternativa-a-excel-y-google-sheets',
    rival: 'Excel y Google Sheets',
    title: 'Alternativa a Excel y Google Sheets para finanzas personales (2026) | Whisper Money',
    description:
        'La hoja de cálculo es gratis y perfecta hasta que dejas de mantenerla. Whisper Money conserva el control de las categorías y te quita el trabajo de meter los datos. Comparativa y guía para importar tu hoja.',
    heading: 'Alternativa a Excel y Google Sheets para tus finanzas',
    intro: 'La hoja de cálculo es la mejor herramienta de finanzas personales que existe, hasta el día en que dejas de mantenerla. Google Sheets es gratuito, Excel viene incluido con Microsoft 365, hace exactamente lo que le pidas y el fichero es tuyo para siempre. Casi todo el mundo que la abandona lo hace por el mismo motivo, y no es la potencia: es el trabajo de meter los datos.',
    rows: [
        {
            dimension: 'Precio',
            rival: 'Google Sheets es gratuito y Excel viene con Microsoft 365. En dinero, imbatible.',
            whisper:
                'Plan gratuito y plan de pago. Esta comparación no la ganamos en precio: la ganamos en el tiempo que te devuelve.',
        },
        {
            dimension: 'De dónde salen los datos',
            rival: 'Los pegas tú: descargar el extracto, ajustar columnas, pegar debajo, arreglar fechas y decimales.',
            whisper:
                'Del banco por PSD2, o del mismo extracto que ya descargabas, con las columnas detectadas y los duplicados marcados.',
        },
        {
            dimension: 'Categorías',
            rival: 'BUSCARV, listas desplegables y algún SI anidado que solo entiendes tú.',
            whisper:
                'Reglas de automatización que se aplican a todo lo que entra, y categorización con IA opcional.',
        },
        {
            dimension: 'Qué pasa si lo dejas un mes',
            rival: 'Vuelves a un fichero con un hueco y la sensación de deuda pendiente.',
            whisper:
                'Las cuentas conectadas siguen sincronizando: cuando vuelves, el mes ya está.',
        },
    ],
    narrativeTitle: 'El problema no es la hoja de cálculo, es el mantenimiento',
    narrative: [
        'Una hoja bien montada te da algo que ninguna aplicación te da: control absoluto del cálculo. Si la tuya funciona y la mantienes al día, no hay ninguna razón para cambiar.',
        'El motivo real por el que se abandonan es aburrido y mecánico. Cada mes hay que descargar los extractos de cada banco, ajustar las columnas porque cada entidad las nombra distinto, arreglar las fechas y los decimales, pegar sin duplicar lo del mes anterior y volver a etiquetar comercios que ya etiquetaste doce veces. Es trabajo que no produce ninguna información nueva. La primera vez que te lo saltas, el fichero deja de estar al día; la segunda, deja de servir para decidir nada.',
        'Whisper Money no intenta ser más flexible que tu hoja, porque no lo será: intenta quitarte ese mantenimiento. Los bancos que conectan entran solos, los que no entran por el mismo fichero que ya descargabas, y las categorías se aplican con reglas que escribes una vez. El plan gratuito es permanente, así que probarlo no te obliga a nada.',
    ],
    bullets: [
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
    migrationIntro:
        'Esta es la mudanza más limpia de todas, porque tu histórico ya está en el formato que el importador espera: una hoja con una fila por movimiento.',
    migrationSteps: [
        {
            title: 'Crea las cuentas que tenías en pestañas',
            body: 'Cada pestaña o cada bloque de tu hoja suele corresponder a una cuenta real. Créalas en Whisper Money, o conecta directamente el banco si sincroniza, antes de importar nada.',
        },
        {
            title: 'Prepara la hoja sin reformatearla',
            body: 'No hace falta rehacer nada. Basta con que cada movimiento sea una fila y que existan una columna de fecha, una de concepto y una de importe con los gastos en negativo. Si llevabas gasto e ingreso en columnas separadas, únelos en una sola con signo.',
        },
        {
            title: 'Guarda o exporta el fichero',
            body: 'Excel vale tal cual: el importador acepta .xls y .xlsx. En Google Sheets, Archivo → Descargar → CSV o Microsoft Excel. Si tu hoja tiene varias pestañas, expórtalas e impórtalas una a una, cada una a su cuenta.',
        },
        {
            title: 'Sube el fichero y confirma el mapeo',
            body: 'Al subirlo se detectan las cabeceras y se propone el mapeo. Confirmas fecha, descripción e importe, y de forma opcional el saldo. Eliges el formato de fecha entre DD-MM-YYYY, YYYY-MM-DD, MM-DD-YYYY y YYYYMMDD y ves las tres primeras filas ya interpretadas antes de seguir. El mapeo queda guardado para esa cuenta.',
        },
        {
            title: 'Reconstruye las categorías con reglas',
            body: 'En la vista previa ves el total, los seleccionados y los duplicados. Al importar se aplican tus reglas de automatización: si antes tenías un BUSCARV que convertía «MERCADONA» en «Supermercado», eso pasa a ser una regla que además se aplicará a todo lo que llegue en el futuro. Lo que quede sin categoría lo resuelve la IA si la activas.',
        },
    ],
    testimonials: [
        {
            name: 'Carla Álvarez',
            gravatar: '9901ee5e849cf9a0caea00e897cb8123',
            text: 'Descubrí Whisper Money y supe al instante que lo necesitaba. Estaba haciéndolo todo en una hoja de cálculo, una tarea que acababa posponiendo siempre. Esto lo hace facilísimo.',
        },
        {
            name: 'Haru',
            gravatar: '3e52d6b2cbefb0fa2a572a588b3f7953',
            text: 'Antes lo ponía todo en un Excel y me llevaba muchísimo tiempo; ahora tengo el banco controlado y los pagos en efectivo los añado manualmente. ¡Gran trabajo!',
        },
    ],
    closingBody:
        'Nadie echa de menos el mantenimiento de la hoja, solo el control. Aquí lo conservas: eliges las categorías, escribes las reglas y decides qué se conecta y qué no. El plan gratuito no pide tarjeta.',
};

const bankApp: ComparisonPage = {
    slug: 'alternativa-a-la-app-de-tu-banco',
    rival: 'la app de tu banco',
    title: 'Alternativa a la app de tu banco para controlar tus gastos (2026) | Whisper Money',
    description:
        'La app de tu banco solo ve lo que pasa por ese banco. Whisper Money reúne todas tus entidades por PSD2, más brokers, cripto, inmuebles y préstamos, con categorías tuyas. Comparativa y guía de migración.',
    heading: 'Alternativa a la app de tu banco',
    intro: 'La app de tu banco es gratis, ya la tienes instalada y sus gráficos de gasto han mejorado mucho en los últimos años. Para quien tiene una sola cuenta y ninguna inversión suele ser suficiente, y decir lo contrario sería venderte algo que no necesitas. La pregunta útil es otra: qué pasa cuando tienes dos bancos, un neobanco, un broker y algo de cripto.',
    rows: [
        {
            dimension: 'Precio',
            rival: 'Gratis, incluida en tu cuenta.',
            whisper:
                'Plan gratuito y plan de pago. Solo tiene sentido pagarlo si tienes dinero en más de un sitio.',
        },
        {
            dimension: 'Qué alcanza a ver',
            rival: 'Lo que pasa por ese banco. Los movimientos de las demás entidades no existen para ella.',
            whisper:
                'Todos tus bancos por PSD2 en la misma pantalla, más brokers, cripto, Wise, inmuebles y préstamos.',
        },
        {
            dimension: 'Categorías',
            rival: 'Las que decide el banco, con la lógica del banco y sin reglas propias.',
            whisper:
                'Las tuyas, con reglas que escribes una vez y se aplican solas a lo que entra.',
        },
        {
            dimension: 'Qué pasa si cambias de banco',
            rival: 'Empiezas de cero: el análisis termina donde termina la relación comercial.',
            whisper:
                'Conectas la cuenta nueva o importas el extracto de la antigua, y la serie continúa.',
        },
    ],
    narrativeTitle: 'El límite: tu banco solo puede contarte su mitad',
    narrative: [
        'La app del banco no tiene un defecto de diseño, tiene un límite de perímetro. Solo puede clasificar lo que pasa por sus cuentas, porque es lo único que ve. En cuanto la nómina entra en un banco, la hipoteca sale de otro, la tarjeta del neobanco paga los viajes y las aportaciones van a un broker, cada app te da una porción y ninguna te da el total.',
        'Y el total es justamente el número que decide cosas: cuánto tienes, cuánto entra, cuánto sale y cómo evoluciona mes a mes. Eso no se calcula sumando cuatro capturas de pantalla a final de mes, entre otras cosas porque nadie lo hace dos meses seguidos.',
        'El segundo límite es de quién es el histórico. Las categorías de la app de tu banco son suyas, y su análisis termina el día que te cambias de entidad. Aquí las categorías las defines tú, el histórico se importa desde cualquier extracto y las cuentas de varias entidades conviven en la misma serie.',
    ],
    bullets: [
        'Todos los bancos en una pantalla, por el mismo estándar PSD2 que tu banco usa para dejarte entrar.',
        'Nunca te pedimos las credenciales de tu banco: la autorización se firma en la web de la propia entidad y se puede revocar desde allí.',
        'Brokers, cripto y Wise en el mismo patrimonio neto: Indexa Capital, Interactive Brokers, Binance, Bitpanda y Coinbase.',
        'Inmuebles y préstamos incluidos, para que el patrimonio neto sea el real y no solo el líquido.',
        'Categorías propias y reglas de automatización, en lugar de la clasificación que decida el banco.',
        'Presupuestos que cruzan entidades: el gasto en supermercado es el mismo aunque pagues con tres tarjetas distintas.',
        'Flujo de caja mensual de ingresos contra gastos, con todas las cuentas sumadas.',
        'Multidivisa con 33 monedas y conversión, útil si cobras o gastas fuera del euro.',
        'Ninguna oferta de producto financiero: no vendemos hipotecas, ni fondos, ni seguros.',
        'Aplicación web e instalable en el móvil, en español, inglés y francés, con el código público.',
    ],
    migrationIntro:
        'No hace falta abandonar la app de tu banco: sigue siendo donde haces las transferencias. Lo que se muda es el análisis. Y el fichero que necesitas es uno que ya sabes descargar.',
    migrationSteps: [
        {
            title: 'Conecta cada entidad',
            body: 'En el alta eliges el banco y autorizas el acceso en su propia web, con tus factores de autenticación habituales. Nosotros recibimos un permiso de lectura, no tus claves, y la autorización se renueva —o se revoca— cuando quieras.',
        },
        {
            title: 'Descarga el histórico que la conexión no traiga',
            body: 'La sincronización trae el histórico que cada entidad entregue, que no siempre es todo. Para los años anteriores, entra en la banca online y exporta los movimientos en CSV, XLS o XLSX: una descarga por cuenta.',
        },
        {
            title: 'Importa cada extracto a su cuenta',
            body: 'En Transacciones abres el importador, eliges la cuenta que ya está conectada y subes el fichero. Los movimientos que la conexión ya trajo se detectan como duplicados y vienen desmarcados, así que puedes solapar periodos sin duplicar nada.',
        },
        {
            title: 'Confirma el mapeo, que casi siempre viene hecho',
            body: 'Las cabeceras de la banca española —Fecha, F. Valor, Concepto, Descripción, Importe, Saldo— se reconocen automáticamente. Confirmas fecha, descripción e importe, eliges el formato de fecha (DD-MM-YYYY en la mayoría de los ficheros españoles) y ves las tres primeras filas interpretadas. El mapeo queda guardado para esa cuenta.',
        },
        {
            title: 'Deja que las reglas hagan el resto',
            body: 'Al importar se aplican tus reglas de automatización y, si la activas, la IA categoriza lo que quede suelto. A partir de ahí las cuentas conectadas entran solas y la app de tu banco vuelve a ser lo que era: el sitio donde mueves dinero.',
        },
    ],
    testimonials: [
        {
            name: 'Marc Dubois',
            gravatar: '226dbaa3b8d04f4641b99ab90884bb9d',
            text: 'Antes tenía tres apps y una hoja de cálculo. Tener todas las cuentas en un sitio es la primera vez que entiendo de verdad a dónde va mi dinero.',
        },
        {
            name: 'Kenji Saito',
            gravatar: '13440a401468cb05cf2c123d48202c1e',
            text: 'Rápida, limpia y con un modo oscuro que no me destroza los ojos de noche. Y no intenta vender mis datos. Es justo todo lo que quería.',
        },
        {
            pending:
                'TODO: testimonio real pendiente de un usuario que solo usaba la app de su banco',
        },
    ],
    closingBody:
        'La app de tu banco seguirá ahí para mover dinero. Lo que cambia es que ahora hay un sitio donde ver todo lo que tienes, no solo la parte que le corresponde a una entidad. El plan gratuito no pide tarjeta.',
};

const wallet: ComparisonPage = {
    slug: 'alternativa-a-wallet-budgetbakers',
    rival: 'Wallet (BudgetBakers)',
    title: 'Alternativa a Wallet de BudgetBakers (2026) | Whisper Money',
    description:
        'Wallet de BudgetBakers no publica su tarifa y su exportación de movimientos es una función premium. Whisper Money publica el precio, incluye la importación en el plan gratuito y añade inversiones y cripto.',
    heading: 'Alternativa a Wallet, de BudgetBakers',
    intro: 'Wallet, de la checa BudgetBakers, es uno de los gestores más veteranos de Europa: declara más de 14 millones de descargas y más de 15.000 conexiones bancarias, y está entre las apps de finanzas que más facturan en España. Es un producto sólido y con años de recorrido. Las diferencias están en el precio, que no publica, y en lo que cuesta mover tus propios datos.',
    rows: [
        {
            dimension: 'Precio',
            rival: 'No publica tarifa en su web: habla de prueba gratuita y de usuarios premium, sin cifras (consultado el 18 de agosto de 2026).',
            whisper:
                'Precio publicado en la página de precios, con plan gratuito y plan de pago en euros.',
        },
        {
            dimension: 'Mover tu histórico',
            rival: 'Exporta a CSV y a XLS desde su web seleccionando movimientos. Su centro de ayuda indica que la exportación es una función premium y que no incluye presupuestos, objetivos ni pagos recurrentes.',
            whisper:
                'La importación de CSV, XLS y XLSX con mapeo de columnas está incluida en el plan gratuito.',
        },
        {
            dimension: 'Inversiones y cripto',
            rival: 'Centrada en cuentas, tarjetas y presupuesto.',
            whisper:
                'Brokers y exchanges dentro del patrimonio neto: Indexa Capital, Interactive Brokers, Binance, Bitpanda, Coinbase y Wise.',
        },
        {
            dimension: 'Qué puedes comprobar',
            rival: 'Código cerrado.',
            whisper: 'Código público en GitHub.',
        },
    ],
    narrativeTitle: 'Lo que cuesta salir',
    narrative: [
        'La comparación interesante con Wallet no es de funciones, porque las tiene casi todas y desde bastante antes que nosotros. Es de reversibilidad: qué te llevas si algún día decides irte.',
        'Su propio centro de ayuda documenta el camino. En la web, sección Records, filtras, seleccionas los movimientos y eliges «Export to CSV» o «Export to xls». Ahí mismo indica que es una función premium y que lo que sale son los movimientos —fecha, importe, categoría y cuenta—, no tus presupuestos, ni tus objetivos, ni los pagos recurrentes que configuraste. Es decir: parte de lo que construiste durante años no viaja contigo.',
        'La otra diferencia es el precio, que su web no publica. Saber lo que vas a pagar antes de instalar nada no es un detalle estético: es lo primero que quieres comparar cuando estás decidiendo.',
        'Nada de esto convierte a Wallet en una mala aplicación. Son dos decisiones de producto distintas, y las dos se pueden comprobar hoy en sus respectivas páginas.',
    ],
    bullets: [
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
    migrationIntro:
        'Wallet exporta a CSV y a XLS, que son exactamente dos de los formatos que el importador espera. La mudanza es un fichero por cuenta.',
    migrationSteps: [
        {
            title: 'Exporta desde Wallet',
            body: 'En su aplicación web entra en Records, filtra por cuenta y por el rango de fechas más amplio que tengas, pulsa «Select all» y usa el botón Export para elegir «Export to CSV» o «Export to xls». Repite la operación cuenta por cuenta: así cada fichero corresponde a una cuenta de destino aquí.',
        },
        {
            title: 'Crea o conecta la cuenta de destino',
            body: 'Si tu banco conecta por PSD2, conéctalo y deja que traiga lo reciente. Si no, crea la cuenta a mano. El importador siempre pregunta a qué cuenta van los movimientos.',
        },
        {
            title: 'Sube el fichero',
            body: 'En Transacciones abres el importador, seleccionas la cuenta y arrastras el .csv o el .xls que acabas de exportar. También acepta .xlsx.',
        },
        {
            title: 'Revisa el mapeo de columnas',
            body: 'El importador propone las columnas por su cabecera. Confirmas fecha, descripción e importe, y puedes unir varias columnas en la descripción —por ejemplo la nota y el beneficiario— y elegir el formato de fecha entre cuatro opciones. La vista previa de tres filas te dice si acertó antes de continuar, y el mapeo se guarda para esa cuenta.',
        },
        {
            title: 'Recupera las categorías con reglas',
            body: 'La exportación de Wallet trae la categoría de cada movimiento, pero no tus reglas. Al importar se aplican las reglas de automatización que hayas creado aquí, y lo que quede sin clasificar lo resuelve la IA si la activas. En la vista previa ves el total, los seleccionados y los duplicados antes de confirmar.',
        },
    ],
    testimonials: [
        {
            name: 'Lena Hoffmann',
            gravatar: '708c64d04836b9a1e25a9caddc13f97b',
            text: 'Suelto el CSV de mi banco y averigua las columnas y las categorías él solo. La parte de ponerme con mis finanzas que más pereza me daba ha desaparecido.',
        },
        {
            name: 'Albert G.',
            gravatar: 'bb92a036f4feb9d12d0a70dd2d9a5c5f',
            text: 'La aplicación es intuitiva, funcional y de gran ayuda para gestionar mis finanzas en el día a día. Lo que más destaca es la cantidad de opciones de la versión gratuita: demuestra vuestro compromiso con los usuarios. ¡Seguiré recomendándola!',
        },
        {
            pending:
                'TODO: testimonio real pendiente de un usuario que venía de Wallet',
        },
    ],
    closingBody:
        'Si ya tienes el fichero exportado, la mudanza son cinco minutos por cuenta. Y si solo quieres probar antes de decidir, el plan gratuito incluye la importación y no pide tarjeta.',
};

const monefy: ComparisonPage = {
    slug: 'alternativa-a-monefy',
    rival: 'Monefy',
    title: 'Alternativa a Monefy (2026) | Whisper Money',
    description:
        'Monefy es rapidísima para apuntar gastos, pero no conecta con bancos: «no bank login required», dice su web. Whisper Money conserva el apunte manual y añade la conexión bancaria y la importación de extractos.',
    heading: 'Alternativa a Monefy',
    intro: 'Monefy es la app de apuntar gastos más rápida que existe: abres, pulsas un icono, escribes el importe y ya está. Es de las más valoradas de España y su núcleo es gratuito, con una mejora opcional para cuentas ilimitadas, recurrentes y filtros avanzados. Su propia web explica la contrapartida sin rodeos: «you can import statements manually or add expenses in seconds, no bank login required».',
    rows: [
        {
            dimension: 'Precio',
            rival: 'Núcleo gratuito y mejora opcional de pago; su web no publica el precio de esa mejora (consultado el 18 de agosto de 2026).',
            whisper:
                'Precio publicado, con plan gratuito y plan de pago en euros.',
        },
        {
            dimension: 'De dónde salen los movimientos',
            rival: 'Los apuntas tú. No conecta con bancos, por decisión de producto: «no bank login required».',
            whisper:
                'Los bancos que conectan por PSD2 entran solos; lo demás se apunta a mano o se importa.',
        },
        {
            dimension: 'Sincronización entre dispositivos',
            rival: 'A través de tu propio Google Drive o Dropbox.',
            whisper:
                'La misma cuenta en el navegador y en el móvil, sin depender de un almacenamiento externo.',
        },
        {
            dimension: 'Alcance',
            rival: 'Gastos e ingresos repartidos por categoría.',
            whisper:
                'Gastos, presupuestos, flujo de caja y patrimonio neto, con brokers, cripto, inmuebles y préstamos.',
        },
    ],
    narrativeTitle: 'Apuntar rápido es gratis; acordarse de apuntar, no',
    narrative: [
        'El diseño de Monefy es acertado, y su decisión de no conectar bancos es deliberada, no una carencia: prioriza simplicidad, velocidad y privacidad, y lo dice en su web. Para quien vive en efectivo, o para quien quiere sentir cada gasto en el momento de apuntarlo, funciona mejor que cualquier agregador.',
        'El problema del registro manual no es el esfuerzo de cada apunte, que son dos segundos. Es la cobertura. Los recibos domiciliados, las suscripciones, la comisión de mantenimiento y la compra que hiciste desde el móvil sin pensar son precisamente los gastos que no apuntas, y son los que descuadran el mes. Un histórico manual acaba siendo el histórico de lo que recordaste, que no es lo mismo que el de lo que gastaste.',
        'Whisper Money conserva la parte manual —puedes apuntar el efectivo en dos toques desde el móvil— y añade lo que la conexión bancaria trae sola. Y si prefieres no conectar el banco, como en Monefy, el importador te deja subir el extracto sin dar credenciales a nadie.',
    ],
    bullets: [
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
    migrationIntro:
        'Monefy exporta tus datos a CSV o Excel, según su propia guía, y eso es todo lo que hace falta. Como en Monefy los movimientos no están separados por cuenta bancaria, lo habitual es importarlos todos a una única cuenta de efectivo y seguir desde ahí.',
    migrationSteps: [
        {
            title: 'Exporta tus registros desde Monefy',
            body: 'Usa su función de exportar a fichero y guarda el CSV donde puedas alcanzarlo desde el ordenador: Drive, Dropbox o tu propio correo sirven igual.',
        },
        {
            title: 'Crea la cuenta de destino',
            body: 'Crea una cuenta de efectivo, que es la que mejor refleja lo que había en Monefy. Los bancos, si quieres tenerlos, los conectas después por separado.',
        },
        {
            title: 'Sube el fichero',
            body: 'En Transacciones abres el importador, eliges esa cuenta y arrastras el CSV. También acepta .xls y .xlsx si lo has pasado por una hoja de cálculo.',
        },
        {
            title: 'Confirma el mapeo de columnas',
            body: 'Confirmas la columna de fecha, la de concepto y la de importe. Si el fichero trae gasto e ingreso separados, únelos antes en una sola columna con los gastos en negativo. Eliges el formato de fecha entre DD-MM-YYYY, YYYY-MM-DD, MM-DD-YYYY y YYYYMMDD y compruebas las tres primeras filas ya interpretadas.',
        },
        {
            title: 'Importa y decide si conectas el banco',
            body: 'En la vista previa ves el total, los seleccionados y los duplicados, desmarcados por defecto. Al confirmar se aplican tus reglas de automatización. Desde ese momento puedes seguir apuntando a mano como en Monefy, conectar el banco para que entre solo, o las dos cosas a la vez.',
        },
    ],
    testimonials: [
        {
            name: 'Haru',
            gravatar: '3e52d6b2cbefb0fa2a572a588b3f7953',
            text: 'Antes lo ponía todo en un Excel y me llevaba muchísimo tiempo; ahora tengo el banco controlado y los pagos en efectivo los añado manualmente. ¡Gran trabajo!',
        },
        {
            name: 'Brian Bansuela',
            gravatar: '9314f776a17ae977871076ac71f2ff60',
            text: 'Acabo de empezar a sincronizar cuentas y ya me parece una gran app. La interfaz me encanta y parece una herramienta muy sólida. ¡Muy buen trabajo!',
        },
        {
            pending:
                'TODO: testimonio real pendiente de un usuario que venía de Monefy',
        },
    ],
    closingBody:
        'Puedes quedarte con lo mejor de Monefy —apuntar en dos toques— y dejar de perseguir los recibos que nunca apuntabas. El plan gratuito no pide tarjeta.',
};

const margen: ComparisonPage = {
    slug: 'margen-vs-whisper-money',
    rival: 'Margen',
    title: 'Margen vs Whisper Money (2026) | Comparativa',
    description:
        'Margen cuesta 4,99 €/mes o 39,99 €/año y funciona solo en iPhone. Whisper Money es una aplicación web instalable en iPhone y Android, con PSD2, brokers, cripto y código público. Comparativa y guía de migración.',
    heading: 'Margen vs Whisper Money',
    intro: 'Margen salió en julio de 2026 con una propuesta clara y bien ejecutada: «Todo tu dinero en un solo número. En vivo». Resuelve el mismo problema que nosotros —el patrimonio neto consolidado— y conecta BBVA, Santander, CaixaBank, Sabadell, ING, Trade Republic y Revolut. Es la comparación más honesta que podemos hacer, porque somos casi lo mismo con dos decisiones distintas.',
    rows: [
        {
            dimension: 'Precio',
            rival: 'Plan gratuito de 0 €/mes y Premium a 4,99 €/mes o 39,99 €/año (getmargen.com, 18 de agosto de 2026).',
            whisper:
                'Plan gratuito y plan de pago en euros, con la tarifa publicada en la página de precios.',
        },
        {
            dimension: 'Dónde funciona',
            rival: 'Solo iPhone: su web ofrece únicamente la descarga en App Store, sin versión web ni Android.',
            whisper:
                'Aplicación web en cualquier navegador, instalable como app en iPhone y en Android.',
        },
        {
            dimension: 'Qué se conecta',
            rival: 'BBVA, Santander, CaixaBank, Sabadell, ING, Trade Republic y Revolut, más «importación con IA de cualquier banco» en Premium.',
            whisper:
                'Bancos españoles y europeos por PSD2, más Indexa Capital, Interactive Brokers, Wise, Binance, Bitpanda y Coinbase.',
        },
        {
            dimension: 'Qué puedes comprobar',
            rival: 'Código cerrado.',
            whisper: 'Código público en GitHub.',
        },
    ],
    narrativeTitle: 'Dos apuestas distintas sobre dónde se miran las finanzas',
    narrative: [
        'Margen apuesta por el móvil y por el gesto de abrir la app para ver un número. Está bien pensado: el patrimonio neto es un dato que se consulta, no que se edita, y para consultar algo el móvil gana siempre.',
        'Nosotros apostamos por lo contrario, y por un motivo concreto. Revisar categorías, cuadrar un mes, montar presupuestos o repasar un extracto de doscientas líneas es trabajo de pantalla grande y teclado. Whisper Money es una aplicación web que además se instala en el móvil, no una app móvil con una web al lado. Si tu relación con tus finanzas consiste en mirar el total una vez al día, la ventaja es de Margen; si además quieres hacer algo con esos datos, cambia de lado.',
        'La segunda diferencia es el alcance de la agregación. Margen publica una lista concreta de entidades; nosotros conectamos por PSD2 contra el catálogo de bancos españoles y europeos, y añadimos brokers y exchanges como integraciones propias. Si tu dinero está en Indexa Capital, en Interactive Brokers o en un exchange de cripto, ahí es donde se nota la diferencia.',
        'Y la tercera es de fondo: nuestro código es público. Puedes leer qué hace la aplicación con tus datos antes de darle acceso a tus cuentas, lo cual es una forma bastante más sólida de confiar que leerse una política de privacidad.',
    ],
    bullets: [
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
    migrationIntro:
        'Margen es reciente y su web no documenta una exportación de movimientos, así que la ruta fiable no pasa por Margen: pasa por las mismas entidades que Margen te conectó. Es el mismo camino que harías al llegar de cualquier agregador.',
    migrationSteps: [
        {
            title: 'Conecta tus bancos por PSD2',
            body: 'Eliges la entidad y autorizas el acceso en la web del propio banco. La sincronización trae el histórico que cada entidad entregue y las cuentas quedan actualizándose solas, sin que tengas que volver a intervenir.',
        },
        {
            title: 'Añade brokers y exchanges',
            body: 'Indexa Capital, Interactive Brokers, Wise, Binance, Bitpanda y Coinbase se conectan con claves de API que generas tú en cada servicio, y que puedes crear con permisos de solo lectura. Aquí es donde el patrimonio neto se completa.',
        },
        {
            title: 'Descarga el histórico largo de tu banco',
            body: 'Para los años que la conexión no traiga, entra en la banca online y exporta los movimientos en CSV, XLS o XLSX: una descarga por cuenta, con el rango más amplio que la entidad te permita.',
        },
        {
            title: 'Impórtalo a la cuenta ya conectada',
            body: 'En Transacciones abres el importador, eliges esa misma cuenta y subes el fichero. Lo que la conexión ya trajo aparece marcado como duplicado y desmarcado, así que no se duplica nada. Las cabeceras españolas se detectan solas y el mapeo queda guardado para esa cuenta.',
        },
        {
            title: 'Traduce tus categorías a reglas',
            body: 'Antes de importar ves el total, los seleccionados y los duplicados. Al confirmar se aplican tus reglas de automatización: crear cuatro o cinco para tus comercios habituales resuelve buena parte del histórico de golpe, y la IA se ocupa del resto si la activas.',
        },
    ],
    testimonials: [
        {
            name: 'Jorge Navarrete',
            gravatar: 'd20d4e05a100d5b20b45c84f3c566a25',
            text: 'Estoy descubriendo la aplicación web y, a nivel de diseño y UX, está excelente. ¡Gracias, chicos!',
        },
        {
            name: 'Miguel Ángel SB',
            gravatar: 'e5da1753042eee6b08a3db0df0b5f807',
            text: 'Me gusta mucho el estilo, la interfaz intuitiva, la facilidad de uso. Si sigue creciendo en esta línea, Whisper Money será mi futura aplicación de finanzas. Se nota que lo hacéis con pasión, y lo que se hace con pasión solo puede acabar siendo un éxito.',
        },
        {
            pending:
                'TODO: testimonio real pendiente de un usuario que venía de Margen',
        },
    ],
    closingBody:
        'Si Margen te convenció del problema y te falta la pantalla grande, los brokers o el código que se puede leer, esto es lo mismo resuelto por el otro lado. El plan gratuito no pide tarjeta.',
};

const dinerio: ComparisonPage = {
    slug: 'dinerio-vs-whisper-money',
    rival: 'Dinerio',
    title: 'Dinerio vs Whisper Money (2026) | Comparativa',
    description:
        'Dinerio cuesta 3,99 €/mes o 35,88 €/año y no conecta con bancos por diseño. Whisper Money conecta por PSD2 sin pedirte credenciales, tiene plan gratuito permanente y el código es público. Comparativa y guía de migración.',
    heading: 'Dinerio vs Whisper Money',
    intro: 'Dinerio es un gestor español de finanzas personales que cuesta 3,99 €/mes o 35,88 €/año, con 14 días de prueba, y que ha tomado una decisión de diseño explícita: «Dinerio no se conecta a cuentas bancarias ni pide credenciales, así que tus datos son solo tuyos». Defiende la privacidad con el mismo argumento que nosotros, pero llegando por el camino opuesto.',
    rows: [
        {
            dimension: 'Precio',
            rival: '3,99 €/mes o 35,88 €/año (equivalente a 2,99 €/mes), con 14 días de prueba (dinerioapp.es, 18 de agosto de 2026).',
            whisper:
                'Plan gratuito permanente y plan de pago en euros, con la tarifa publicada en la web.',
        },
        {
            dimension: 'Conexión bancaria',
            rival: 'No conecta con bancos, por diseño. Los movimientos los introduces tú.',
            whisper:
                'Conexión por PSD2 opcional. Si prefieres no conectar, importas el extracto y trabajas igual que en Dinerio.',
        },
        {
            dimension: 'Credenciales',
            rival: 'No las pide, porque no hay conexión.',
            whisper:
                'No las pedimos tampoco: la autorización PSD2 se firma en la web de tu banco y nosotros solo recibimos un permiso de lectura revocable.',
        },
        {
            dimension: 'Qué puedes comprobar',
            rival: 'Código cerrado. Declara datos cifrados en servidores europeos (Supabase, Frankfurt).',
            whisper:
                'Código público en GitHub: la promesa de privacidad se puede leer, no solo creer.',
        },
    ],
    narrativeTitle: 'Dos maneras de no tocar tus credenciales',
    narrative: [
        'El argumento de Dinerio es coherente y merece explicarse bien: si la app nunca conecta con tu banco, no hay credenciales que filtrar ni permiso que revocar. Es una forma legítima de resolver el problema, y el precio de esa tranquilidad es que todo lo teclea el usuario.',
        'Conviene aclarar algo, porque es la confusión más común de este mercado: conectar el banco por PSD2 no significa dar tus claves a nadie. PSD2 es la normativa europea de banca abierta. La autorización se firma en la web de tu propio banco, con tus factores de autenticación, y lo que la aplicación recibe es un permiso de lectura, temporal y revocable desde el banco cuando quieras. Nosotros no vemos ni almacenamos tu usuario ni tu contraseña en ningún momento.',
        'Así que la diferencia real no es privacidad contra comodidad: es quién hace el trabajo de meter los datos. En Dinerio lo haces siempre tú. Aquí eliges: conectas y entra solo, o no conectas e importas el extracto, que es exactamente el mismo dato que alimentarías a mano en Dinerio pero sin teclearlo línea a línea.',
        'Y sobre quién es más comprobable, la respuesta vale para las dos: mira el código. El nuestro es público.',
    ],
    bullets: [
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
    migrationIntro:
        'La web de Dinerio no documenta una exportación de movimientos, así que la vía fiable es la misma con la que alimentabas Dinerio: el extracto de tu banco. La diferencia es que aquí lo subes en lugar de teclearlo.',
    migrationSteps: [
        {
            title: 'Crea tu cuenta y decide si conectas',
            body: 'Si quieres seguir sin conexión bancaria, crea las cuentas a mano y salta al paso tres. Si prefieres que entre solo, conecta la entidad autorizando el acceso en la web del banco; puedes revocarlo desde allí cuando quieras.',
        },
        {
            title: 'Comprueba qué trae la conexión',
            body: 'La primera sincronización descarga el histórico que tu entidad entregue y deja la cuenta actualizándose sola. Lo que falte se completa importando, y no hace falta que decidas esto ahora: puedes importar más tarde.',
        },
        {
            title: 'Descarga tus extractos',
            body: 'En la banca online de cada cuenta exporta los movimientos al periodo más amplio que te permita, en CSV, XLS o XLSX. Si llevabas la contabilidad a mano y tienes ese histórico en una hoja de cálculo, también sirve tal cual.',
        },
        {
            title: 'Sube el fichero y confirma el mapeo',
            body: 'Eliges la cuenta de destino y arrastras el fichero. Las cabeceras de la banca española se detectan automáticamente: confirmas fecha, descripción e importe, y de forma opcional el saldo, el ordenante y el beneficiario. Eliges el formato de fecha entre cuatro opciones y ves las tres primeras filas ya interpretadas. El mapeo se guarda para esa cuenta.',
        },
        {
            title: 'Revisa duplicados y confirma',
            body: 'La vista previa te da el total, los seleccionados y los duplicados, comparados con lo que ya hay en la cuenta y desmarcados por defecto. Al importar se aplican tus reglas de automatización, y la IA categoriza lo que quede suelto si la has activado.',
        },
    ],
    testimonials: [
        {
            name: 'Tom',
            gravatar: 'd721bb1875ac11132d4d33295867cbd9',
            text: 'Estoy realmente contento usando un proyecto open-source con un compromiso real con la privacidad: es exactamente lo que quiero de una app de finanzas.',
        },
        {
            name: 'Ricardo Rovira',
            text: 'La aplicación me gusta mucho, es justo lo que buscaba. Me parece muy chulo el proyecto que habéis realizado y creo que habéis dado con la tecla con lo que debe ser una aplicación de gestión de finanzas personales, por lo menos como lo quiero gestionar yo.',
        },
        {
            pending:
                'TODO: testimonio real pendiente de un usuario que venía de Dinerio',
        },
    ],
    closingBody:
        'Si te convenció el argumento de privacidad de Dinerio, aquí lo tienes con dos añadidos: el código que puedes leer y la opción de que los movimientos entren solos cuando te apetezca. El plan gratuito no pide tarjeta.',
};

export const COMPARISON_PAGES: ComparisonPage[] = [
    fintonic,
    ynab,
    spreadsheets,
    bankApp,
    wallet,
    monefy,
    margen,
    dinerio,
];

export function findComparisonPage(slug: string): ComparisonPage | undefined {
    return COMPARISON_PAGES.find((page) => page.slug === slug);
}
