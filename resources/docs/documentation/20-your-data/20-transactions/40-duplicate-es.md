# Duplicar una transacción

Algunos gastos se repiten igual cada mes: la factura del móvil, la cuota del gimnasio, la transferencia del alquiler. Si los apuntas a mano, duplicar copia una transacción que ya tienes con la fecha de hoy, así que solo te queda revisar y guardar.

{{TOC}}

## Inicio rápido

1. Busca la transacción que quieres repetir.
2. Abre su menú (el botón **⋯** o clic derecho sobre la fila) y elige **Duplicar**.
3. Se abre **Agregar Transacción** con todo rellenado y la fecha de hoy.
4. Cambia lo que haga falta, como el importe si este mes ha variado.
5. Guarda.

![El menú de una transacción abierto, con Duplicar justo debajo de Editar](/docs/documentation/duplicate-transaction-menu.png)

## Cuándo duplicar

Cuando lo que vas a apuntar ya lo apuntaste antes y solo cambia el día. Duplicar te ahorra volver a elegir la categoría, las etiquetas y la cuenta, y que la transacción de este mes se llame igual que la del anterior, para que tus filtros y presupuestos la encuentren.

Si la transacción llega desde tu banco o desde una importación, no hace falta: aparecerá sola.

## Qué se copia y qué no

![El diálogo Agregar Transacción rellenado con el importe, la descripción, la cuenta y la categoría de la original, con la fecha de hoy](/docs/documentation/duplicate-transaction-dialog.png)

La copia lleva la **descripción**, el **importe** (y si es gasto o ingreso), la **moneda**, la **categoría**, las **etiquetas** y las **notas** de la original. Las etiquetas y las notas esperan en **Más opciones**.

La **fecha** no se copia: siempre es hoy. Cámbiala en el diálogo si la transacción era de otro día.

La **cuenta** es la de la original mientras esa cuenta siga admitiendo transacciones. Si no, el diálogo elige la cuenta que usaría al [crear una transacción](/documentation/transactions/create) nueva.

La copia es siempre una transacción manual, aunque la original viniera de tu banco. Tampoco hereda la referencia del banco ni los nombres de acreedor y deudor.

## Las reglas de automatización no se ejecutan

Al guardar un duplicado, tus reglas de automatización no se aplican. La copia ya lleva la categoría y las etiquetas que decidiste para la original, y eso es lo que se queda.

Solo el primer guardado es el duplicado. Si sigues con **Guardar y añadir otra**, lo que apuntes después es una transacción nueva normal y las reglas se ejecutan como siempre.

## Dónde está

**Duplicar** aparece en el menú de cada transacción en la página de transacciones, y también en las listas de la página de una cuenta, de un presupuesto y de un objetivo de ahorro.

No aparece en las partes de una [división](/documentation/transactions/split): una parte solo tiene sentido junto a las demás.

## Preguntas frecuentes

### ¿Y si me arrepiento?

Cierra el diálogo. No se crea nada hasta que guardas.

### ¿Duplicar cambia la transacción original?

No. La original se queda exactamente como estaba.

### ¿Se actualiza el saldo de la cuenta?

Igual que al crear una transacción a mano: en una cuenta que llevas tú, el diálogo te deja mover el saldo a la vez. En una cuenta conectada a un banco, el saldo lo sigue poniendo el banco.
