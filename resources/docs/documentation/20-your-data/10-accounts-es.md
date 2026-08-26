# Cuentas

Las cuentas son la base de Whisper Money. Guardan saldos, transacciones e historial.

{{TOC}}

## Inicio rápido

1. Crea una cuenta por cada lugar donde tienes o debes dinero.
2. Elige el tipo que mejor encaje con la cuenta real.
3. Añade saldos para cuentas que solo necesitan seguimiento de valor.
4. Importa transacciones para cuentas con actividad diaria.
5. Revisa la página de Cuentas para ver saldos y evolución del patrimonio neto.

## Mapa de cuentas

```mermaid
flowchart TD
    %% diagram: account-map-es
    account[Cuenta] --> balances[Historial de saldos]
    account --> transactions[Transacciones]
    balances --> networth[Patrimonio neto]
    transactions --> cashflow[Flujo de efectivo]
```

## Tipos de cuenta

<div class="cards-wrapper">

<div class="card">
### Corriente

Usa este tipo para cuentas bancarias del día a día.

Útil para:

- Ingresos de salario
- Pagos con tarjeta
- Facturas
- Gasto diario

</div>

<div class="card">
### Ahorro

Usa este tipo para dinero que mantienes apartado.

Útil para:

- Fondo de emergencia
- Objetivos a corto plazo
- Dinero que no gastas a diario

</div>

<div class="card">
### Tarjeta de crédito

Usa este tipo para tarjetas de crédito.

Las tarjetas de crédito quedan fuera del patrimonio neto por completo. Son cuentas
de gasto, no patrimonio, así que el saldo se sigue en la propia cuenta y no se
suma ni se resta a tu total.

</div>

<div class="card">
### Inversión

Usa este tipo para cuentas de broker o inversión.

Normalmente son cuentas de solo saldo. Sigues su valor en el tiempo en vez de transacciones diarias.

</div>

<div class="card">
### Jubilación

Usa este tipo para pensiones o cuentas de jubilación.

Como las inversiones, suelen centrarse en historial de saldo y crecimiento a largo plazo.

</div>

<div class="card">
### Préstamo

Usa este tipo para dinero que debes.

Ejemplos:

- Hipoteca
- Préstamo personal
- Préstamo estudiantil

Los préstamos son el único tipo de cuenta que reduce el patrimonio neto: el
importe debido se resta de tus activos.

</div>

<div class="card">
### Inmueble

Usa este tipo para el valor de una propiedad.

Puedes seguir el valor de mercado y enlazar un préstamo cuando la propiedad tiene hipoteca.

</div>

<div class="card">
### Otros

Usa este tipo cuando ningún otro encaje.

Mantén un nombre claro para recordar qué representa la cuenta.

</div>
</div>

## Cuentas transaccionales y cuentas de solo saldo

Algunas cuentas se siguen mejor con transacciones. Otras se siguen mejor con saldos.

Usa transacciones para:

- Cuentas corrientes
- Tarjetas de crédito
- Cuentas de ahorro con movimientos frecuentes

Usa saldos para:

- Cuentas de inversión
- Cuentas de jubilación
- Inmuebles
- Préstamos

## Saldos, valores de mercado e importes debidos

Whisper Money usa palabras distintas según el tipo de cuenta.

- Las cuentas normales usan **saldo**.
- Las cuentas de préstamo usan **importe debido**.
- Las cuentas de inmueble usan **valor de mercado**.

Así el lenguaje se acerca más a lo que significa el número.

## Cuentas conectadas y manuales

Puedes llevar cuentas manualmente o conectar proveedores compatibles.

Las cuentas manuales son útiles cuando:

- Tu banco no está soportado.
- Quieres control total.
- Solo necesitas actualizar de vez en cuando.

Las cuentas conectadas son útiles cuando:

- Quieres actualizaciones automáticas de transacciones.
- Quieres menos trabajo manual.
- La conexión bancaria está disponible y funciona bien.

Solo las cuentas corrientes, de ahorro, de tarjeta de crédito y de tipo «otros»
pueden recibir transacciones sincronizadas. Las de inversión, jubilación,
inmueble y préstamo se siguen por valor, así que una conexión actualiza su saldo
en lugar de rellenar un historial de movimientos.

Conectar un banco forma parte del plan de pago. Las cuentas manuales, las
importaciones y todo lo que se construye sobre ellas funcionan sin él.

La [página de integraciones](/integraciones) lista todos los bancos y aplicaciones
que se pueden conectar hoy.

## Archivar una cuenta

Archiva una cuenta que ya no uses en lugar de borrarla.

Una cuenta archivada:

- Desaparece de la página de cuentas y de los selectores para datos nuevos.
- Conserva sus transacciones y su historial de saldos, así que los meses
  anteriores mantienen las cifras que ya tenían.
- Se puede recuperar en cualquier momento desde la configuración de Cuentas bancarias.

Archivar no es lo mismo que ocultar una cuenta del panel. Ocultarla solo la quita
de esa vista; la cuenta sigue siendo seleccionable en todo lo demás.

## Cuentas compartidas

Una cuenta puede registrar qué parte de ella es tuya. Una cuenta conjunta al
50/50 aporta la mitad de cada importe a tus propias cifras, mientras la cuenta
sigue mostrando el saldo real.

Úsalo para cuentas que de verdad compartes, como una cuenta doméstica común o un
inmueble en copropiedad.

## Preguntas frecuentes

### ¿Por qué mi préstamo reduce el patrimonio neto?

Un préstamo es dinero que debes. Whisper Money lo resta de tus activos al calcular el patrimonio neto.

### ¿Por qué mi tarjeta de crédito no reduce el patrimonio neto?

Una tarjeta de crédito es una cuenta de gasto, no patrimonio. Whisper Money sigue
lo que debes en la propia tarjeta y lo deja fuera del total de patrimonio neto,
así que pagar la tarjeta no mueve esa cifra.

### ¿Por qué los inmuebles usan valor de mercado?

El número importante de una propiedad es su valor estimado actual. Ese valor puede cambiar con el tiempo.

### ¿Debería crear una cuenta o combinar varias?

Crea cuentas separadas cuando el dinero esté separado en la vida real. Los informes serán más claros.
