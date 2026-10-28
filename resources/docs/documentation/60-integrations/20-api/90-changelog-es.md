# Registro de cambios

Cada cambio de la API que pueda afectar a tu código, del más reciente al más antiguo.

{{TOC}}

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
