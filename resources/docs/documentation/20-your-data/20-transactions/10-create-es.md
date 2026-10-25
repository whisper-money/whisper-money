# Crear una transacción

Añade una transacción a mano cuando no ha llegado desde un archivo del banco ni desde una cuenta conectada: efectivo, una devolución entre amigos o algo que el banco nunca registró.

{{TOC}}

## Inicio rápido

1. Pulsa **+ Transacción** encima de tu lista de transacciones.
2. Elige **Gasto** o **Ingresos** y escribe el importe.
3. Escribe una descripción.
4. Revisa los chips de cuenta, fecha y categoría que hay debajo.
5. Pulsa **Crear Transacción**, o **⌘ Enter** en un Mac y **Ctrl Enter** en los demás.

## Dónde está el botón

- **Página de transacciones**: **+ Transacción** es el primer botón de la barra que hay justo encima de la lista, antes de Análisis y Categorizar. En un móvil muy estrecho se queda en un **+**.
- **Página de una cuenta**: el mismo botón está encima de las transacciones de esa cuenta, y el diálogo se abre con esa cuenta ya elegida.
- **Cabecera de la página**: si añades a mano buena parte de tus transacciones, **+ Transacción** aparece también en la cabecera de todas las páginas, junto al botón de importar. Se muestra cuando al menos una cuarta parte de las transacciones que añadiste en los últimos 30 días las escribiste tú. Puede tardar hasta una hora en aparecer o desaparecer después de que eso cambie.

Si no tienes ninguna cuenta que admita transacciones, por ejemplo cuando solo tienes una cuenta de inversión, el botón aparece apagado y te indica que antes necesitas una cuenta.

## Añadido rápido

El diálogo se abre en corto: el importe, la descripción y una fila de chips con valores razonables. En la mayoría de transacciones no hay nada más que revisar.

![El diálogo Agregar Transacción en su forma corta: el selector de gasto o ingreso y el importe arriba, la descripción debajo y una fila de chips para la cuenta, la fecha, una categoría ya elegida desde su chip y el saldo](/docs/documentation/transaction-quick-add.png)

- **Cuenta**: la última cuenta en la que añadiste una transacción a mano. En la página de una cuenta, esa cuenta. Púlsalo para elegir otra.
- **Fecha**: **Hoy**. Púlsalo para ver el campo de fecha y elegir otro día.
- **Categoría**: **Sin categorizar**, a propósito. Púlsalo para buscar y elegir una. Si lo dejas así, tus [reglas de automatización](/documentation/automation-rules) aún pueden rellenarla al guardar.
- **Saldo**: **Actualiza el saldo** o **Sin tocar el saldo**. Púlsalo para cambiarlo. Consulta [Actualizar el saldo a la vez](#actualizar-el-saldo-a-la-vez).

**Más opciones** abre el formulario completo: los campos de fecha y cuenta, la categoría, las etiquetas, las notas y la casilla del saldo. **Menos opciones** lo vuelve a plegar.

![El diálogo Agregar Transacción con Más opciones abierto: el importe, la casilla Actualizar balance de cuenta, la descripción, los campos de fecha y cuenta, y los selectores de categoría y etiquetas](/docs/documentation/create-transaction-dialog.png)

## Qué hay que rellenar

Cuatro campos son obligatorios: **importe**, **descripción**, **fecha** y **cuenta**.

- El **importe** se escribe sin signo: el selector **Gasto / Ingresos** que tiene al lado decide si el dinero sale o entra.
- La **descripción** es lo que reconocerás más tarde, y lo que leen las reglas de automatización.
- La **fecha** decide en qué mes se informa la transacción. «Hoy» es hoy en la zona horaria de tu dispositivo.
- La **cuenta** decide a qué saldo pertenece la transacción.

La **categoría** y las **etiquetas** son opcionales y se pueden añadir después en cualquier momento. Al buscar en el selector de categorías, una categoría padre aparece con todas sus subcategorías, así que al escribir el nombre de la categoría padre también salen todas las que cuelgan de ella. Las notas esperan detrás del botón **Agregar nota** hasta que hagan falta.

Los nombres de acreedor y deudor no forman parte de este diálogo. Solo existen en las transacciones para las que un banco los ha facilitado.

## Pagar en otra moneda

El selector de moneda que hay dentro del campo de importe empieza en la moneda de la cuenta. Cámbialo cuando pagaste en otra, como un hotel en dólares cargado a una cuenta en euros. Escribe el importe que pagaste de verdad, en esa moneda.

Cuando las dos monedas no coinciden, el diálogo muestra cuánto es en la moneda de la cuenta, con el tipo de cambio de la fecha de la transacción. Al elegir otra cuenta, la moneda vuelve a la de esa cuenta.

Si no hay tipo de cambio para ese día, el diálogo muestra solo el importe que escribiste, y el saldo de la cuenta no se mueve aunque tengas activada la opción del saldo. Actualízalo a mano si hace falta.

## Añadir varias seguidas

**Guardar y añadir otra** guarda la transacción y deja el diálogo abierto para la siguiente. La cuenta, la fecha, Gasto o Ingresos, la categoría y las etiquetas se quedan como estaban. El importe, la descripción y las notas se vacían, y el cursor vuelve al importe.

Es la forma más rápida de meter de una vez un montón de tiques.

## Atajos de teclado

| Atajo                                              | Qué hace                                                                                                                            |
| -------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------- |
| **⌘ Enter** en un Mac, **Ctrl Enter** en los demás | Guarda: **Crear Transacción** al añadir, **Guardar Cambios** al editar. Funciona aunque sigas escribiendo en un campo.              |
| **N**                                              | Abre las notas y pone el cursor en ellas. No hace nada mientras escribes en un campo, ni al añadir antes de abrir **Más opciones**. |
| **Esc**                                            | Cierra el diálogo sin guardar.                                                                                                      |

En una pantalla más ancha que un móvil, los botones muestran su atajo junto al nombre.

## Actualizar el saldo a la vez

En una cuenta que llevas a mano, el diálogo ofrece actualizar el saldo de la cuenta: el chip **Actualiza el saldo** en la forma corta, la casilla **Actualizar balance de cuenta** en **Más opciones**. Déjalo activado y el saldo de la cuenta se mueve por el importe de la transacción, así que saldo y transacción van a la par. Se recuerda si lo dejaste activado o no para la próxima vez.

En una cuenta conectada a un banco la opción no aparece: el banco es la fuente de ese saldo, y la siguiente sincronización sobrescribiría cualquier cosa que se pusiera aquí. Una tarjeta de crédito tampoco la ofrece, porque no tiene un saldo propio que mover.

## Las reglas de automatización siguen ejecutándose

Una transacción creada a mano se compara con tus reglas de automatización como cualquier otra. Una regla solo rellena lo que has dejado vacío: si eliges tú la categoría, la regla no la sustituye.

Si una regla eligió la categoría mientras la forma corta la tenía oculta, el aviso que aparece al guardar dice qué categoría ha puesto y te deja deshacer el guardado. En los demás casos, el aviso nombra la regla que ha coincidido.

La excepción es una transacción [duplicada](/documentation/transactions/duplicate), que conserva lo que tenía la original.

## Editar una transacción

Pulsa una transacción de la lista para abrir el mismo diálogo con lo que contiene. **Guardar Cambios** la guarda, y el botón de la papelera que tiene al lado la elimina después de pedirte confirmación. No hay botón de cancelar: el aspa, **Esc** o pulsar fuera lo cierran sin guardar.

Una transacción que creaste tú se puede cambiar entera. Una que llegó de tu banco o de un archivo muestra sus datos bancarios en solo lectura, y deja editar la categoría, las etiquetas, las notas y la fecha. Consulta [¿Puedo cambiar la fecha de una transacción que envió mi banco?](/documentation/transactions#puedo-cambiar-la-fecha-de-una-transaccion-que-envio-mi-banco).

## Preguntas frecuentes

### ¿Por qué el importe se guarda como número negativo?

Porque el dinero que sale es un importe negativo en todo Whisper Money. Informes, presupuestos y flujo de caja dependen del signo. Nunca lo escribes tú: el selector **Gasto / Ingresos** del diálogo lo pone por ti.

### ¿Puedo crear una transacción en otra moneda?

Sí. Elige la moneda en el campo de importe. La transacción guarda el importe en esa moneda, y los totales y el saldo de la cuenta lo usan convertido al tipo de cambio de la fecha de la transacción.

### ¿Qué día se le pone a una transacción que añado de noche?

El de hoy. Las fechas son días del calendario en la zona horaria de tu dispositivo, así que una transacción añadida a las 23:30 queda con fecha de hoy, no de mañana, y se ve en ese mismo día mires donde la mires.
