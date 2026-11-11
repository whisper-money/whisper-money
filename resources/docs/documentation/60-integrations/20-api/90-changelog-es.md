# Registro de cambios

Cada cambio de la API que pueda afectar a tu código, del más reciente al más antiguo.

{{TOC}}

## 2026-11-11 · Gasto por etiqueta, uso de tarjetas y ahorro del mes

Tres cifras que enseña el panel de la app, para toda tu cuenta:
[`GET /api/v1/analytics/spending-by-label`](/documentation/api/reference/analytics#retrieve-spending-by-label)
ordena tus etiquetas por gasto en un rango frente al periodo anterior,
[`GET /api/v1/analytics/credit-card-usage`](/documentation/api/reference/analytics#retrieve-credit-card-usage)
da cuánto de cada tarjeta de crédito está en uso, con su evolución día a día, y
[`GET /api/v1/analytics/monthly-savings`](/documentation/api/reference/analytics#retrieve-this-months-savings)
suma el mes en curso de tus objetivos de ahorro mensual y dice cómo fue el mes
pasado. El Conector de IA lo tiene como las herramientas `spending_by_label`,
`get_credit_card_usage` y `get_monthly_savings`.

## 2026-11-11 · Agregar transacciones

[`GET /api/v1/analytics/aggregate`](/documentation/api/reference/analytics#aggregate-transactions)
agrupa tus transacciones de un espacio por hasta dos dimensiones (día, semana,
mes, año, categoría, etiqueta, cuenta o tipo) y devuelve por grupo su suma,
número, media, importe más pequeño y más grande. Cada moneda va en su propio
grupo y, al agrupar por periodo, salen todos los periodos del rango, también
los que quedan a cero. El Conector de IA lo tiene como la herramienta
`aggregate_transactions`.

## 2026-10-28 · v1: primera versión

La primera versión pública de la API, en `https://whisper.money/api/v1`. Cubre
todo lo que puede hacer el [Conector de IA](/documentation/ai-connector), y
añade un endpoint para leer por su id una cuenta, una transacción, una
categoría, una etiqueta, una regla de automatización, un presupuesto o un
objetivo de ahorro:

- **Espacios**: lista los espacios a los que tienes acceso.
- **Cuentas** y sus **saldos**: lista, lee, crea y actualiza cuentas; lista y
  registra saldos.
- **Transacciones**: busca, lee, crea, actualiza, categoriza, etiqueta, divide,
  vuelve a unir una división y borra.
- **Categorías** y **etiquetas**: lista, lee, crea, actualiza y borra.
- **Reglas de automatización**: lista, lee, crea, actualiza, borra y aplica una
  a las transacciones que ya existen.
- **Presupuestos**: lista, lee, crea, actualiza y borra.
- **Objetivos de ahorro**: lista, lee, crea y actualiza.
- **Analítica**: flujo de caja, patrimonio y gasto por categoría.
- **Medallas**: lista tus medallas.

Los permisos son los de la app: la API solo llega a tus propios datos, también
en un [espacio compartido](/documentation/api/spaces). La cuenta, transacción,
categoría, etiqueta, regla u objetivo de otro miembro responde `404`, y su id
se rechaza en el cuerpo de una petición. Igual que en la app, una transacción
del banco o de una importación se puede borrar y mover de fecha, pero su
importe y sus nombres se quedan como los envió el banco.

La autenticación funciona con [tokens personales o apps OAuth](/documentation/api/authentication),
y toda la API está descrita en la [especificación OpenAPI](https://whisper.money/api/v1/openapi.json).

## Cómo funcionarán las versiones

`/api/v1` se queda como está. No quitamos campos ni endpoints, ni cambiamos lo
que significan.

Cuando haga falta un cambio incompatible, llegará como una versión con fecha que
eliges con la cabecera `Whisper-Version`, y las peticiones sin la cabecera
seguirán comportándose como hoy. Esa cabecera aún no está activa: enviarla ahora
no tiene efecto. Los cambios que no pueden romper un cliente, como un endpoint
nuevo o un campo nuevo en una respuesta, se añaden directamente a la v1 y se
anotan aquí.
