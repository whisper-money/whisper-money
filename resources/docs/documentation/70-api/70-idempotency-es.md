# Idempotencia

Envía una cabecera `Idempotency-Key` con un `POST` y podrás reintentarlo tras un timeout sin crear lo mismo dos veces.

{{TOC}}

## Por qué importa

Cuando una petición para crear algo da timeout, no sabes si ha fallado o si solo
se ha perdido la respuesta. Reintentarla a ciegas podría registrar la misma
transacción dos veces. Una clave de idempotencia hace que el reintento sea
seguro: la API recuerda la primera respuesta y la repite.

## Cómo usarla

Genera un valor único para cada operación, por ejemplo un UUID, y envíalo como
cabecera. Envía el mismo valor cuando reintentes esa operación:

```bash
curl -X POST https://whisper.money/api/v1/transactions \
  -H "Authorization: Bearer $WHISPER_MONEY_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: 9b2f4c1e-5d3a-4e7b-8c6d-0a1b2c3d4e5f" \
  -d '{"description": "Café"}'
```

La cabecera es opcional, funciona en cualquier `POST` y se ignora en los demás
métodos. `PATCH` y `PUT` ya se pueden repetir sin riesgo, y repetir un `DELETE` solo
responde `404` la segunda vez.

## Qué pasa al reintentar

- **La primera petición fue bien.** La API devuelve el mismo estado y el mismo
  cuerpo sin volver a ejecutar la petición, con la cabecera
  `Idempotent-Replayed: true`. Las claves se recuerdan durante 24 horas.
- **La primera petición sigue en curso.** La API responde `409` con el código
  `idempotency_conflict`. Espera un momento y reintenta.
- **La primera petición falló.** Los errores no se recuerdan, así que el
  reintento se ejecuta de nuevo. Puedes corregir el cuerpo y reenviarlo con la
  misma clave.
- **Misma clave, otra petición.** Reutilizar una clave con otra ruta u otro
  cuerpo responde `422`, con el error en `Idempotency-Key`.

Las claves son tuyas: otro usuario que envíe el mismo valor no choca con el
tuyo.

Una clave debe tener entre 1 y 255 caracteres; cualquier otra cosa responde
`400` con el código `invalid_idempotency_key`.
