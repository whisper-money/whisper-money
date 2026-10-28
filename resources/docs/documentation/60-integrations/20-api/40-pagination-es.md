# Paginación

Las listas que pueden crecer mucho llegan página a página, y las recorres con un cursor: el id del último objeto que recibiste.

{{TOC}}

## Parámetros

| Parámetro        | Descripción                                               |
| ---------------- | --------------------------------------------------------- |
| `limit`          | Cuántos objetos devolver, entre 1 y 100. Por defecto, 25. |
| `starting_after` | Un id de objeto. Devuelve la página que va después de él. |
| `ending_before`  | Un id de objeto. Devuelve la página que va antes de él.   |

Envía `starting_after` o `ending_before`, nunca los dos.

## La respuesta

Una página son los objetos en `data`, más `has_more`:

```json
{
    "data": [
        { "id": "0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60" },
        { "id": "0199a3f2-71aa-7c03-8e51-6b0d9f4e2c18" }
    ],
    "has_more": true
}
```

`has_more` indica si quedan más objetos después de esta página, en la dirección
en la que estás recorriendo la lista.

## Recorrer una lista

Pide la primera página y luego pasa el id del último objeto recibido como
`starting_after` hasta que `has_more` sea `false`:

```bash
curl "https://whisper.money/api/v1/transactions?limit=100" \
  -H "Authorization: Bearer $WHISPER_MONEY_TOKEN"

curl "https://whisper.money/api/v1/transactions?limit=100&starting_after=0199a3f2-71aa-7c03-8e51-6b0d9f4e2c18" \
  -H "Authorization: Bearer $WHISPER_MONEY_TOKEN"
```

Para ir hacia atrás, pasa como `ending_before` el primer id de la página que
tienes.

## Orden

Cada lista tiene un orden fijo, y no hay parámetro `sort`:

- Transacciones: primero la `transaction_date` más reciente.
- Saldos: primero la fecha más reciente.
- Todo lo demás: primero lo más reciente, por fecha de creación.

El cursor es estable: los objetos que se añaden mientras recorres la lista no te
hacen saltar ni repetir los que aún no has visto.

Un id de cursor que no existe, o que pertenece a otro espacio, responde `400` con
el código `invalid_cursor`.
