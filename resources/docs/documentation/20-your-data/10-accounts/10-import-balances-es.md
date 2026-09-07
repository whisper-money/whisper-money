# Importar saldos

Trae a una cuenta un historial completo de saldos desde un archivo CSV o Excel, para que el gráfico de patrimonio neto llegue tan atrás como tus registros.

## Cuándo es la herramienta adecuada

Una cuenta que llevas a mano no tiene historial de saldos hasta que se lo das. Introducir una cifra al mes funciona, pero si tu banco o tu bróker puede exportar un extracto de saldos, importarlo es más rápido y llega mucho más atrás.

Los saldos son independientes de las transacciones: [importar transacciones](/documentation/transactions/import) no fija saldos, salvo que el archivo lleve por casualidad una columna de saldo acumulado.

Una cuenta conectada también ofrece esto. Importar es la forma de que su historial llegue más atrás del día en que la conectaste, porque un banco manda poco de lo anterior. Las fechas de hoy en adelante son la excepción: cada sincronización vuelve a escribir el saldo de hoy, y el de cualquier fecha posterior cuando llegue ese día, así que esas filas se reemplazan.

## Dónde está

El botón **Importar saldos** de la página de la cuenta. En una cuenta de inmueble se lee _Importar valores de mercado_, y en un préstamo _Importar importes pendientes_: el mismo panel, nombrado según lo que guarda esa cuenta.

## Qué necesita el archivo

Con dos columnas basta: una fecha y un saldo. Las cuentas de inversión, jubilación y ahorro pueden mapear además un **importe invertido**, que es lo que pusiste, frente a lo que vale ahora.

![El paso de mapeo de columnas de la importación de saldos, con las columnas de fecha y saldo emparejadas y el importador preguntando en qué orden se leen las fechas](/docs/documentation/import-balances-mapping.png)

Las fechas se leen sin preguntar cuando el archivo no deja duda, como pasa con `2026-03-31`. Cuando sí admite dos lecturas (`03/04/2026` es el 3 de abril o el 4 de marzo), se te pregunta cuál es en lugar de adivinarlo: equivocarse ahí mueve un año de historial.

El paso de vista previa muestra las primeras filas tal como se han leído. Si ahí las fechas o los importes se ven mal, vuelve atrás y cambia el mapeo en lugar de importar y arreglarlo después.

## Después de importar

El gráfico de saldo de la cuenta y su aportación al patrimonio neto se redibujan con los saldos importados. Se guarda un saldo por fecha, así que importar un archivo que solapa un periodo que ya tenías reemplaza esas fechas en lugar de duplicarlas.

Las filas que no se han podido leer se listan por número de fila al terminar la importación, con lo que fallaba. Corrígelas en el archivo e impórtalo otra vez.

## Preguntas frecuentes

### ¿Necesito una fila por día?

No. Los saldos se leen como el valor en esa fecha, y el gráfico los une. Con filas mensuales basta para un gráfico de patrimonio neto que se lea bien.

### ¿Puedo importar saldos de varias cuentas en un archivo?

No. Una importación va a la cuenta que has elegido. Separa el archivo por cuenta.
