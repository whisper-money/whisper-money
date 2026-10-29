# Importes y monedas

Todos los importes son enteros en la unidad más pequeña de su propia moneda, junto a la moneda en la que están.

{{TOC}}

## Enteros en unidades mínimas

La API nunca envía ni acepta decimales para el dinero. Un importe es un número
entero de la unidad mínima de la moneda, y el objeto al que pertenece lleva su
`currency` como código ISO 4217:

```json
{
    "amount": -1250,
    "currency": "EUR"
}
```

Eso son −12,50 €: 1.250 céntimos. Los importes negativos son dinero que sale y
los positivos, dinero que entra.

## No todas las monedas tienen céntimos

Cuántas unidades mínimas forman una unidad depende de la moneda, así que nunca
dividas entre 100 a ciegas. Lee antes la `currency`:

| Moneda                     | Unidades mínimas por unidad | `1250` significa |
| -------------------------- | --------------------------- | ---------------- |
| EUR, USD, GBP y la mayoría | 100                         | 12,50            |
| JPY, COP, CLP, PYG, PKR    | 1                           | 1.250            |
| KWD                        | 1.000                       | 1,250            |
| BTC                        | 100.000.000                 | 0,0000125        |

Cuando envíes un importe, usa las unidades mínimas de la moneda de la cuenta u
objeto al que corresponde.

La única excepción son las `conditions` de una regla de automatización: un
importe que se compara ahí va en unidades principales y con signo, así que un
gasto de 12,50 es `-12.5`.

## Fechas

Las fechas son cadenas ISO 8601: `2026-01-15` para un día del calendario, como
el de una transacción, y `2026-01-15T09:30:00+00:00` para un instante, como el
momento en que se creó algo.
