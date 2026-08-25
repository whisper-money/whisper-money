# Importaciones

Las importaciones te permiten traer archivos bancarios a Whisper Money cuando la sincronización automática no está disponible o cuando quieres más control.

{{TOC}}

## Inicio rápido

1. Elige la cuenta.
2. Sube el archivo del banco.
3. Mapea las columnas.
4. Revisa la vista previa.
5. Importa las transacciones seleccionadas.
6. Revisa categorías y duplicados después de importar.

## Flujo de importación

```mermaid
flowchart TD
    account[Elegir cuenta] --> file[Subir archivo]
    file --> mapping[Mapear columnas]
    mapping --> preview[Vista previa]
    preview --> import[Importar]
    import --> review[Revisar transacciones]
```

## Columnas necesarias

<div class="cards-wrapper">

<div class="card">
### Fecha

La fecha de la transacción.

Whisper Money puede detectar formatos comunes, pero puedes ajustarlo si hace falta.

</div>

<div class="card">
### Descripción

El texto que explica la transacción.

Puedes combinar columnas de descripción cuando el banco separa detalles en varios campos.

</div>

<div class="card">
### Importe

El importe de la transacción.

Asegúrate de que ingresos y gastos usan el signo correcto.

</div>

<div class="card">
### Saldo

Opcional.

Úsalo cuando el archivo incluye saldos de cuenta acumulados.

</div>
</div>

## Saldos e importaciones

Algunos archivos incluyen una columna de saldo y otros no.

Cuando el archivo la tiene, mapéala y el historial de saldos acompaña a la
importación. Cuando no la tiene, la importación trae solo las transacciones, y la
cuenta conserva los saldos que hayas introducido tú.

Calcular el historial de saldos a partir de las transacciones y un saldo conocido
todavía no está disponible. Hasta que lo esté, añade un saldo a mano en la cuenta
cuando quieras que el gráfico de patrimonio neto sea correcto.

## Vista previa antes de importar

Revisa siempre la vista previa.

Busca:

- Filas marcadas como duplicadas que en realidad son dos pagos reales del mismo
  importe el mismo día.
- Fechas incorrectas.
- Importes con el signo equivocado.
- Transacciones duplicadas.
- Descripciones vacías.
- Filas vacías inesperadas.

## Automatización durante la importación

Las reglas de automatización pueden ayudar a categorizar transacciones importadas.

Funciona mejor cuando las descripciones son consistentes. Si siempre importas el mismo formato de archivo bancario, las reglas son muy útiles.

## Preguntas frecuentes

### ¿Qué archivo debería usar?

Usa la exportación más limpia que ofrezca tu banco. CSV y archivos tipo hoja de cálculo suelen ser los más fáciles.

### ¿Por qué los importes están invertidos?

Algunos bancos exportan gastos como números positivos. Revisa la vista previa antes de importar.

### ¿Puedo importar el mismo archivo dos veces?

Sí. Antes de la vista previa, Whisper Money compara cada fila con las
transacciones que ya tiene la cuenta y marca las que coinciden en el mismo día,
importe y descripción. Las filas marcadas se desmarcan por ti, así que volver a
importar el archivo trae solo lo nuevo. El número se muestra encima de la vista
previa, y puedes volver a marcar una fila si de verdad es un pago repetido.
