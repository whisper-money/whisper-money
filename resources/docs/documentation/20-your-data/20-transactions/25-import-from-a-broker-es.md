# Importar desde un bróker

Whisper Money reconoce las exportaciones de Trade Republic y MyInvestor. No hay columnas que mapear: el archivo se identifica solo, separa el efectivo de la cartera de valores y cada parte va a su propia cuenta, que se crea en la importación si todavía no la tienes.

{{TOC}}

## Inicio rápido

1. Descarga el archivo del bróker, como se explica en [Trade Republic](#trade-republic)
   o [MyInvestor](#myinvestor).
2. En **Transacciones**, pulsa **Importar transacciones**.
3. Elige la cuenta del bróker. Si todavía no la tienes, pulsa **Sube el archivo y
   la creamos**.
4. Sube el archivo tal cual. El panel te dice qué exportación es.
5. Revisa a qué cuenta va cada parte, el saldo de hoy y, si hay cartera, su valor
   actual.
6. Revisa la vista previa e importa.

## Trade Republic

### Qué archivo descargar

Trade Republic da una exportación de transacciones en CSV que recoge, en un solo
archivo, los movimientos de tu cuenta de efectivo y las operaciones con valores.
Se genera desde la app, entre los extractos de tu perfil, eligiendo un periodo.

- Elige el periodo más largo, desde que abriste la cuenta. La cartera se calcula
  con las compras y ventas que trae el archivo, así que un periodo corto deja
  fuera lo que invertiste antes.
- Sube el CSV tal y como sale. No hace falta abrirlo ni tocar nada.
- Las exportaciones antiguas, separadas por punto y coma, también se reconocen.
- Los extractos en PDF no sirven: solo el CSV de transacciones.

![El paso del archivo con una exportación de Trade Republic reconocida: 22 movimientos de enero a febrero de 2026, sin mapeo de columnas](/docs/documentation/broker-import-trade-republic-upload.png)

### Qué se importa

El archivo de Trade Republic va a dos cuentas.

| Parte del archivo  | Cuenta                                              | Qué se guarda                                                            |
| ------------------ | --------------------------------------------------- | ------------------------------------------------------------------------ |
| Efectivo           | «Trade Republic», de tipo **Cuenta Corriente**      | Una transacción por cada movimiento de efectivo y el saldo de cada día.  |
| Cartera de valores | «Trade Republic · Portfolio», de tipo **Inversión** | El importe invertido de cada día y el valor de hoy. Ninguna transacción. |

En el efectivo entra todo lo que mueve dinero: pagos con tarjeta, transferencias,
recibos, intereses, dividendos, Saveback, bonificaciones, comisiones, impuestos y
también las compras y ventas de valores, que salen de esa misma cuenta. Cada
importe es lo que cambió el saldo, con la comisión y el impuesto incluidos.

Las transacciones se describen así:

- Los pagos con tarjeta, con el nombre del comercio.
- Las compras, ventas y dividendos, como **Compra · nombre**, **Venta · nombre** y
  **Dividendo · nombre**, con el nombre del valor.
- Los intereses, como **Intereses**, y el Saveback, como **Saveback**.
- El resto, con el nombre de la contraparte o la descripción de Trade Republic.

La cartera recoge acciones, ETF, planes de inversión y criptomonedas. Su saldo de
cada día es lo invertido hasta ese día: lo que pagaste en las compras menos lo
que cobraste en las ventas. Una venta con ganancia resta más de lo que costó, así
que lo invertido puede bajar mucho, incluso por debajo de cero. Las acciones que
llegan sin mover efectivo, como las recompensas en cripto o un traspaso desde
otro bróker, no cuentan como invertido. Si no quieres traer la cartera, elige
**No importar la cartera**.

## MyInvestor

MyInvestor da un archivo por producto: uno con los movimientos de la cuenta
remunerada y otro con las operaciones de la cartera de valores y fondos. Cada uno
se importa por separado y va a su propia cuenta.

| Archivo                             | Cuenta                                          | Qué se guarda                                                            |
| ----------------------------------- | ----------------------------------------------- | ------------------------------------------------------------------------ |
| Movimientos de la cuenta remunerada | «MyInvestor», de tipo **Ahorros**               | Una transacción por movimiento y el saldo de cada día.                   |
| Operaciones de valores y fondos     | «MyInvestor · Portfolio», de tipo **Inversión** | El importe invertido de cada día y el valor de hoy. Ninguna transacción. |

Si tienes las dos cosas, importa los dos archivos: no se pisan. El de valores no
trae efectivo, así que no duplica nada de la cuenta remunerada. El dinero que
pasas de la cuenta a la cartera y las suscripciones de fondos que se pagan desde
la cuenta llegan con el archivo de la cuenta remunerada.

### Movimientos de la cuenta remunerada

En el detalle de tu cuenta, pulsa **Ver más información** y, en **Documentación**,
elige **Resumen de movimientos**. Descarga en Excel todos los movimientos de
efectivo de la cuenta. El **Extracto de cuenta** es un PDF y no sirve.

Se reconocen tres formas de este archivo:

- El Excel **Resumen de movimientos**, con unas filas de título encima de la
  cabecera.
- El Excel de movimientos que empieza con las filas **Titular** y **Cuenta**, y
  tiene las columnas **Fecha operación**, **Fecha valor**, **Movimiento**,
  **Importe** y **Saldo**.
- El CSV separado por punto y coma, con las columnas **Fecha de operación**,
  **Fecha de valor**, **Concepto**, **Importe** y **Divisa**.

Cuando el archivo trae la columna **Saldo**, el saldo de cada día sale de ella, y
el de hoy es el último del archivo. Cuando no la trae, el saldo de hoy se calcula
sumando los movimientos y puedes corregirlo.

![El paso del bróker con un Excel de la cuenta remunerada de MyInvestor: la cuenta que se va a crear y el saldo de hoy sacado del archivo](/docs/documentation/broker-import-myinvestor-cash.png)

### Operaciones de valores y fondos

Es la exportación de las órdenes de tu cartera de valores y fondos, que MyInvestor
descarga como `salida.xls` (o `salida.xlsx`). Súbela tal cual, aunque al abrirla
las cabeceras no cuadren con las columnas: se lee igual.

El listado de **Órdenes** de MyInvestor no es este archivo y no sirve. Su
exportación trae las columnas **Fecha de la orden**, **ISIN**, **Importe
estimado**, **Nº de participaciones** y **Estado**, pero no el tipo de cada
orden, así que no se distingue una compra de un reembolso. Si lo subes, el panel
te avisa de que necesitas `salida.xls`.

Qué se lee de cada operación:

- **Compras y suscripciones** suman a lo invertido; **ventas y reembolsos**
  restan lo que cobraste por ellos.
- **Traspasos y switches** entre fondos no cambian lo invertido: el dinero sale de
  un fondo y entra en otro.
- Las **aportaciones a planes de pensiones** cuentan como invertido. Como no
  llevan ISIN, el paso te avisa de que no se pueden asociar a un fondo. Las
  **prestaciones** de un plan restan, como una venta.
- Los **dividendos** no se importan desde este archivo. Si se cobran en la
  cuenta remunerada, llegan con su archivo.

![El paso del bróker con las operaciones de valores de MyInvestor: lo invertido, el valor actual por rellenar y el aviso de una aportación a un plan de pensiones sin ISIN](/docs/documentation/broker-import-myinvestor-securities.png)

## El panel de importación, paso a paso

### Sin la cuenta todavía

El primer paso pide la cuenta a la que va el archivo. Si ya tienes la cuenta del
bróker, elígela. Si no, pulsa **Sube el archivo y la creamos**, al lado de
**¿Vienes de Trade Republic o MyInvestor y aún no tienes la cuenta?**, y pasas
directamente al archivo.

![El primer paso del panel de importación, con la opción de subir el archivo de Trade Republic o MyInvestor sin elegir cuenta](/docs/documentation/broker-import-without-account.png)

### El archivo se reconoce

Al subir el archivo, el panel dice qué exportación es, cuántos movimientos u
operaciones trae y de qué meses. Con una exportación de un bróker no hay paso de
mapeo de columnas: al pulsar **Siguiente** vas al paso del bróker. Un archivo que
no se reconoce sigue el camino de siempre, con su mapeo de columnas.

### Elige las cuentas

El paso del bróker tiene un recuadro por cada parte del archivo, con un selector
de **Cuenta**:

- **Crear «Trade Republic» · Cuenta Corriente**, **Crear «MyInvestor» · Ahorros**
  y, para la cartera, **Crear «Trade Republic · Portfolio» · Inversión** o
  **Crear «MyInvestor · Portfolio» · Inversión** crean la cuenta al importar. Si tienes
  el banco del bróker en tu lista, la cuenta se crea en él.
- También puedes elegir una cuenta que ya tengas. Si ya tienes una cuenta de ese
  bróker, viene elegida.
- Si eliges una cuenta que no es de Trade Republic para el efectivo de Trade
  Republic, el paso te avisa por si te has equivocado, aunque te deja seguir.
- En una cuenta que ya existe, el paso te dice desde qué día se sustituyen sus
  saldos diarios por los del archivo.

![El paso del bróker de Trade Republic: el efectivo y la cartera de valores, cada uno con su cuenta, el saldo de efectivo de hoy y lo invertido](/docs/documentation/broker-import-trade-republic.png)

### El saldo de hoy

El saldo de cada día se reconstruye hacia atrás desde el de hoy. Si el saldo de
hoy es el correcto, los saldos de cada día cuadran aunque el archivo no empiece
cuando abriste la cuenta.

- En Trade Republic, el **Saldo de efectivo hoy** se calcula sumando los
  movimientos del archivo, lo que solo es correcto si el archivo empieza cuando
  abriste la cuenta. Si no coincide con el que muestra la app de Trade
  Republic, corrígelo.
- En MyInvestor, si el archivo trae saldos, el **Saldo hoy** es el último del
  archivo y no se edita. Si no los trae, se calcula y puedes corregirlo.

### El valor de la cartera

El archivo dice cuánto invertiste, pero no cuánto vale hoy lo que tienes. Al lado
de **Invertido**, copia en **Valor actual** el valor total que muestra la app del
bróker. Si lo dejas vacío, la cartera vale lo invertido hasta que lo actualices.

### Vista previa e importación

Pulsa **Vista previa**. Arriba ves dónde quedan las cuentas: el efectivo de hoy,
lo invertido y, si escribiste el valor actual, ese valor y la ganancia. Debajo, los movimientos de efectivo
con el saldo de cada día. Los que ya están en la cuenta vienen desmarcados.

![La vista previa de una importación de Trade Republic: el efectivo de hoy, lo invertido y los movimientos con su saldo](/docs/documentation/broker-import-trade-republic-preview.png)

Un archivo de valores de MyInvestor no trae transacciones: la vista previa lista
las operaciones como referencia y el botón es **Importar el historial de la
cartera**.

Al importar, las transacciones pasan por tus
[reglas de automatización](/documentation/automation-rules), como en cualquier
otra importación.

## Qué queda en Whisper Money

- **Cuentas.** Las que elegiste o las que se crearon: una de efectivo y, si la
  importaste, una de inversión. Se crean en la moneda del archivo.
- **Transacciones.** Solo en la cuenta de efectivo, una por movimiento, cada una
  con su identificador para reconocerla en la siguiente importación.
- **Saldos.** El de cada día en la cuenta de efectivo, desde el primer movimiento
  del archivo hasta hoy.
- **Cartera.** En la cuenta de inversión, un saldo por día con lo invertido hasta
  ese día, y el de hoy con el valor actual que escribiste. Las posiciones de cada
  valor (acciones, participaciones) no se guardan.

## Volver a importar

Puedes importar una exportación nueva siempre que quieras; lo normal es el mismo
periodo completo, con los meses nuevos incluidos.

- **Las cuentas se recuerdan.** El paso dice **Reconocemos el archivo y las
  cuentas de la última vez** y muestra **Mismas cuentas que la otra vez**, aunque
  les hayas cambiado el nombre. Pulsa **Cambiar** para elegir otras.
- **No se duplican transacciones.** Trade Republic da a cada movimiento su propio
  identificador. MyInvestor no, así que se crea uno con el día, el importe y el
  concepto; dos movimientos iguales el mismo día siguen siendo dos. Lo que ya
  está en la cuenta sale desmarcado en la vista previa.
- **Ves qué ha cambiado.** Junto a lo invertido aparece cuánto ha cambiado
  **desde la última vez**, y debajo del valor actual, el que pusiste la última vez
  y su fecha.
- **Los saldos se rehacen.** Los saldos diarios desde el primer día del archivo se
  sustituyen por los nuevos. Con un archivo de Trade Republic o de la cuenta
  remunerada de MyInvestor, solo cuando entra algún movimiento nuevo: si no hay
  nada nuevo, los saldos se quedan como estaban, también los de la cartera de
  Trade Republic. El archivo de valores de MyInvestor los rehace siempre.

## Si la cuenta está conectada a tu banco

Una cuenta conectada al banco recibe sus movimientos y su saldo con la
[sincronización](/documentation/accounts/bank-connections), así que el archivo no
le escribe nada: lo que trae saldría duplicado.

- **Trade Republic.** Si la cuenta de efectivo está conectada, solo se importa la
  cartera, y la vista previa lista las operaciones como referencia. Si además
  eliges **No importar la cartera**, no queda nada que importar.
- **MyInvestor, cuenta remunerada.** Si la cuenta está conectada, el archivo no
  tiene nada que importar en ella y el paso no te deja seguir.
- **MyInvestor, valores y fondos.** No cambia nada: ese archivo solo escribe en la
  cuenta de inversión.

## Preguntas frecuentes

### ¿Tengo que crear las cuentas antes?

No. Pulsa **Sube el archivo y la creamos** en el primer paso, y las cuentas se
crean al importar con los nombres que ves en el paso del bróker. Después puedes
renombrarlas: la siguiente importación las reconoce igual.

### ¿Qué archivo de MyInvestor uso?

Los dos, si tienes cuenta y cartera. El **Resumen de movimientos** trae el
efectivo de la cuenta remunerada, y el archivo de operaciones de valores trae lo
invertido en fondos, acciones y planes de pensiones. Si solo usas la cuenta, basta
con el primero.

### ¿Puedo importar el listado de Órdenes de MyInvestor?

No. Ese listado no dice si cada orden es una compra o un reembolso, así que no se
puede saber cuánto invertiste. Descarga el archivo de operaciones de valores
(`salida.xls`) y súbelo en su lugar.

### ¿Por qué la cartera vale lo mismo que lo invertido?

Porque el archivo no trae precios. El pasado se guarda como lo invertido, y hoy
vale lo que escribas en **Valor actual**. Si lo dejaste vacío, escríbelo en la
siguiente importación o corrige el saldo de hoy de la cuenta de inversión en
[Editar saldos](/documentation/accounts/edit-balances).

### ¿Se guardan mis acciones y fondos uno por uno?

No. La cartera es una cuenta de inversión con lo invertido y su valor de cada
día. Las participaciones de cada valor no se guardan.

### El saldo de efectivo no cuadra con el de Trade Republic. ¿Qué hago?

Corrige **Saldo de efectivo hoy** en el paso del bróker con el que muestra Trade
Republic. El saldo de cada día se reconstruye hacia atrás desde esa cifra.

### ¿Puedo importar un archivo que se solapa con el anterior?

Sí. Lo que ya estaba en la cuenta se reconoce por su identificador y sale
desmarcado, así que solo entra lo nuevo. Para la cartera, usa siempre una
exportación desde el principio: lo invertido se calcula solo con las operaciones
del archivo.

### ¿Puedo importar el efectivo de Trade Republic en una cuenta que ya tengo?

Sí, elígela en el selector de **Cuenta**. Si no es una cuenta de Trade Republic,
el paso te lo avisa por si te has equivocado. Sus saldos diarios desde el primer
día del archivo se sustituyen por los del archivo.

### ¿El archivo pasa por la IA?

No. El archivo se lee en tu navegador, y en una exportación de un bróker no hay
mapeo de columnas que sugerir.
