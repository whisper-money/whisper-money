# Editar saldos

Corrige el historial de saldos de una cuenta que llevas a mano: cambia una cifra, arregla una fecha o borra un registro que no debería estar.

## Dos formas de entrar

**Actualizar saldo**, en la página de la cuenta, escribe el saldo de hoy. Es la rápida: acabas de mirar el banco y quieres que el número cuadre.

**Ver saldos**, en el menú `⋯` de la cuenta, abre el historial completo, un registro por fecha, y te deja cambiar o borrar cualquiera de ellos.

![El modal de historial de saldos, con un saldo por fecha y acciones de editar y borrar en cada fila](/docs/documentation/balances-modal.png)

Las dos se nombran según lo que guarda la cuenta: _valores de mercado_ en una cuenta de inversión o de jubilación, _importe pendiente_ en un préstamo.

Una cuenta conectada no tiene ninguna de las dos. Sus saldos llegan del banco en cada sincronización, así que aquí no hay nada que corregir: arréglalo en el banco, o la siguiente sincronización lo devuelve a como estaba.

## Editar un registro

Abre un registro para cambiar su **fecha** y su **importe**. Las cuentas de inversión, jubilación y ahorro llevan además un **importe invertido**, que es lo que pusiste y no lo que vale ahora.

Hay un saldo por fecha. Mover un registro a una fecha que ya tiene uno reemplaza el saldo de esa fecha, así que el historial nunca acaba con dos respuestas para el mismo día.

## Borrar un registro

Borrar quita esa fecha del historial, y los gráficos se redibujan con los registros que quedan. Borra un registro porque estaba mal, no para ordenar la lista: un historial más pobre hace que el gráfico de la cuenta sea menos exacto, no más pequeño.

## Qué cambia esto

Los saldos son de lo que se dibujan el gráfico de patrimonio neto y el de la propia cuenta. Las transacciones no: añadir una transacción a mano puede mover el saldo con ella, pero editar un saldo antiguo no crea ni cambia ninguna transacción.

## Preguntas frecuentes

### He corregido el saldo y el patrimonio neto sigue mal.

Revisa las demás cuentas. Una cuenta a la que nunca le has dado un saldo no tiene nada que aportar, así que se lee como cero hasta que se lo pongas. [Cuentas](/documentation/accounts) explica qué tipos de cuenta cuentan para el patrimonio neto y cuáles no.

### ¿Puedo editar saldos que vienen de un banco?

No. Esas cuentas no ofrecen la opción, porque la siguiente sincronización sobrescribiría la edición.
