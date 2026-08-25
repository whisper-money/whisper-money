# Crear una transacción

Añade una transacción a mano cuando no ha llegado desde un archivo del banco ni desde una cuenta conectada: efectivo, una devolución entre amigos o algo que el banco nunca registró.

## Dónde está el diálogo

El botón **Transacción** de la página de transacciones lo abre. También se abre desde la página de una cuenta, con esa cuenta ya elegida.

![El diálogo de crear transacción, con los campos de cuenta, fecha, descripción e importe rellenos y debajo los selectores de categoría y etiquetas](/docs/documentation/create-transaction-dialog.png)

## Qué hay que rellenar

Cuatro campos son obligatorios: **cuenta**, **fecha**, **descripción** e **importe**.

- La **cuenta** decide a qué saldo pertenece la transacción, y su divisa.
- La **fecha** decide en qué mes se informa la transacción.
- La **descripción** es lo que reconocerás más tarde, y lo que leen las reglas de automatización.
- El **importe** es negativo para el dinero que sale y positivo para el que entra.

**Categoría**, **etiquetas** y **notas** son opcionales y se pueden añadir después en cualquier momento.

Los nombres de acreedor y deudor no forman parte de este diálogo. Solo existen en las transacciones para las que un banco los ha facilitado.

## Actualizar el saldo a la vez

En una cuenta que llevas a mano, el diálogo ofrece **Actualizar saldo de la cuenta**. Déjalo marcado y el saldo de la cuenta se mueve por el importe de la transacción, así que saldo y transacción van a la par.

En una cuenta conectada a un banco la opción no aparece: el banco es la fuente de ese saldo, y la siguiente sincronización sobrescribiría cualquier cosa que se pusiera aquí.

## Las reglas de automatización siguen ejecutándose

Una transacción creada a mano se compara con tus reglas de automatización como cualquier otra. Una regla solo rellena lo que has dejado vacío: si eliges tú la categoría, la regla no la sustituye. Cuando una regla coincide, su nombre se muestra al guardar.

## Preguntas frecuentes

### ¿Por qué el importe se guarda como número negativo?

Porque el dinero que sale es un importe negativo en todo Whisper Money. Informes, presupuestos y flujo de caja dependen del signo.

### ¿Puedo crear una transacción en otra divisa?

No. Una transacción toma la divisa de su cuenta.
