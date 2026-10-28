# Errores

La API responde con los códigos de estado HTTP estándar, y todos los cuerpos de error tienen la misma forma: un mensaje para ti y un código para tu programa.

{{TOC}}

## El cuerpo del error

```json
{
    "message": "This token is read-only. Create a read & write token to make changes.",
    "code": "insufficient_scope"
}
```

- `message` explica qué ha fallado. Está escrito para desarrolladores, en inglés,
  y puede cambiar, así que no compares con él.
- `code` es estable. Decide en función de él.

Algunos errores añaden campos propios, indicados abajo.

## Errores de validación

Una petición con datos no válidos responde `422` con el cuerpo estándar de
Laravel: el primer mensaje, y cada campo no válido con sus mensajes en `errors`.

```json
{
    "message": "The name field is required.",
    "errors": {
        "name": ["The name field is required."]
    }
}
```

## Códigos

| Estado | Código                    | Cuándo                                                                                                                 |
| ------ | ------------------------- | ---------------------------------------------------------------------------------------------------------------------- |
| `400`  | `invalid_cursor`          | El id de `starting_after` o `ending_before` no está en la lista. Consulta [Paginación](/documentation/api/pagination). |
| `400`  | `invalid_idempotency_key` | La cabecera `Idempotency-Key` está vacía o supera los 255 caracteres.                                                  |
| `401`  | `unauthenticated`         | No hay token bearer, o es desconocido, ha caducado o se ha revocado.                                                   |
| `403`  | `insufficient_scope`      | El token no puede hacer esto: un token de solo lectura en una escritura, o un token del Conector de IA.                |
| `403`  | `plan_required`           | La cuenta no tiene plan de pago. El cuerpo añade `subscribe_url`.                                                      |
| `403`  | `not_account_owner`       | La cuenta es de otro miembro del espacio: todos pueden leerla, pero solo su propietario puede editarla.                |
| `403`  | `shared_account`          | Las cuentas de demostración compartidas no pueden usar la API.                                                         |
| `404`  | `not_found`               | No hay ningún objeto con ese id, o el endpoint no existe.                                                              |
| `405`  | `method_not_allowed`      | El endpoint existe, pero no con ese método HTTP.                                                                       |
| `409`  | `idempotency_conflict`    | Sigue en curso una petición con la misma `Idempotency-Key`. Consulta [Idempotencia](/documentation/api/idempotency).   |
| `422`  | (ninguno)                 | Ha fallado la validación, como se muestra arriba.                                                                      |
| `429`  | `too_many_requests`       | Se ha agotado el límite de uso. Consulta [Límites de uso](/documentation/api/rate-limits).                             |
| `500`  | `internal_server_error`   | Algo ha fallado por nuestra parte. Puedes reintentar más tarde; el mensaje no da detalles.                             |

Un objeto que pertenece a otra persona responde `404`, igual que uno que no
existe.
