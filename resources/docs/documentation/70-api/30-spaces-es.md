# Espacios

Tus datos se organizan en espacios: tu espacio personal y cualquier espacio que alguien haya compartido contigo. Los endpoints que trabajan sobre uno aceptan un parámetro `space` opcional.

{{TOC}}

## Qué es un espacio

Un espacio agrupa un conjunto de cuentas y todo lo que se registra en ellas:
transacciones, categorías, etiquetas, presupuestos. Todo el mundo tiene un
espacio personal, y un espacio se puede compartir con otras personas.

La API te da exactamente lo mismo que la app: tus propios datos. En un espacio
compartido, solo lees y cambias las cuentas, transacciones, categorías,
etiquetas, reglas y objetivos que has creado tú, y cada id que envías debe ser
de uno de los tuyos. Un objeto de otro miembro responde `404`, igual que uno que
no existe.

Lista los espacios a los que tienes acceso, con sus ids:

```bash
curl https://whisper.money/api/v1/spaces \
  -H "Authorization: Bearer $WHISPER_MONEY_TOKEN"
```

## El parámetro `space`

Los endpoints que leen o escriben datos dentro de un espacio aceptan un
parámetro `space` opcional con el id de un espacio. Si no lo envías, el endpoint
trabaja sobre tu espacio personal.

En un `GET`, envíalo en la query string:

```text
GET /api/v1/accounts?space=0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60
```

En un `POST` o `PATCH`, envíalo en la query string o en el cuerpo JSON.

Un id al que no tienes acceso responde `422`, con el error en `space`. La
[referencia](/documentation/api/reference) incluye `space` entre los parámetros
de cada endpoint que lo acepta.

El id de un objeto también se busca en ese espacio. Para leer o cambiar una
cuenta de un espacio compartido, envía el id de ese espacio junto a ella: sin
él, la cuenta se busca en tu espacio personal y responde `404`.

La analítica cubre toda tu cuenta y no un solo espacio, igual que en la app.
