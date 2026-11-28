# Botzy WP Sync · API REST

Las tres operaciones reciben POST con `Content-Type: application/json` y la clave del sitio en `apikey`. Usa HTTPS.

| Operación | Ruta desde la raíz de WordPress | Cuándo llamarla |
|---|---|---|
| Descubrimiento | `/wp-json/botzy/capabilities` | Al conectar o actualizar el paso. Envía solo `apikey`. |
| Búsqueda | `/wp-json/botzy/search` | Al recibir la consulta. |
| Ficha | `/wp-json/botzy/item` | Al elegir una opción. Envía `apikey`, el `id`, `allowPrivate` y, si corresponde, `showRestrictedTitles`. |

El descubrimiento devuelve `operations` con método, URL y argumentos, `post_types` con los tipos habilitados y sus campos fijos, `item_fields` con los campos básicos, `dynamic_field_prefixes`, `max_limit`, `language_filter` y `allow_private`. Los tipos públicos personalizados se habilitan en **Botzy Sync → Contenidos para Botzy**. Con WooCommerce activo se anuncian SKU, precio, moneda y estado de existencias para productos.

Cada ficha detecta automáticamente los campos de ACF y los metadatos de WordPress, incluidos los que guardan los metaboxes como metadatos de la publicación. Las claves aparecen bajo `fields` con el prefijo `meta.`. Los valores simples se devuelven como texto y las listas simples se convierten a texto separado por comas. Se omiten los metadatos protegidos, los objetos y las estructuras anidadas.

## Petición de búsqueda

```json
{"apikey":"CLAVE_DEL_SITIO","q":"renovación","post_type":"post","lang":"es","limit":2,"allowPrivate":false}
```

`q` admite hasta 512 bytes. `post_type` usa `post` por defecto y debe estar habilitado. `limit` es un entero positivo que WordPress ajusta al máximo del administrador. `lang` admite códigos como `es` o `es-VE`. Cuando el sitio anuncia filtrado por idioma se compara con el identificador de idioma de Polylang. En sitios sin ese filtro, la búsqueda usa el catálogo habilitado. Las propiedades desconocidas producen `invalid_query`.

### Acceso en búsqueda y selección por ID

Botzy envía `allowPrivate` en cada petición. `showRestrictedTitles` es un booleano opcional que vale `false` por defecto. El descubrimiento anuncia ambos parámetros en `operations` y sus valores iniciales en `defaults`. Las claves de las fichas completas y restringidas se anuncian en `item_fields` y `restricted_item_fields`.

| allowPrivate | showRestrictedTitles | Resultado |
|---|---|---|
| true, con permiso en WordPress | Cualquiera | Las fichas públicas y privadas llevan `access: "available"`. Los enlaces privados incluyen el JWS temporal. |
| true, con el acceso privado desactivado en WordPress | Cualquiera | Se aplica el mismo comportamiento que con `allowPrivate: false`, según el valor de `showRestrictedTitles`. |
| false | false | Solo se entregan y cuentan fichas públicas. Una búsqueda con coincidencias exclusivamente privadas devuelve `empty`. |
| false | true | Se entregan primero las fichas públicas completas. Los lugares restantes pueden contener títulos privados con exactamente `access`, `id` y `title`. Si solo hay títulos restringidos, el estado es `found`. |

La búsqueda de títulos restringidos usa solo el título, incluida la coincidencia aproximada. `item` vuelve a comprobar el acceso para el ID recibido antes de generar un enlace. Ambas operaciones excluyen contenidos con contraseña y respetan la visibilidad de búsqueda de productos y el ajuste de ocultar productos agotados.

## Ejemplos de respuesta

`image_url` contiene la URL de la imagen destacada o, si falta, de la primera imagen utilizable del contenido. Si la publicación no tiene una imagen utilizable, el valor es `null`.

**Una coincidencia sin imagen ni resumen · HTTP 200**

```json
{"status":"found","returned_count":1,"items":[{"access":"available","id":"184","type":"post","title":"Renovación","summary":"","url":"https://tienda.example/renovacion","image_url":null,"fields":{}}]}
```

**Una ficha pública y un título privado con límite de dos · HTTP 200**

```json
{"status":"found","returned_count":2,"items":[
  {"access":"available","id":"184","type":"post","title":"Renovación","summary":"","url":"https://tienda.example/renovacion","image_url":null,"fields":{}},
  {"access":"restricted","id":"219","title":"Renovación anual"}
]}
```

El primer elemento es el principal. Cada ID es una cadena válida dentro de la conexión que lo devolvió. Para seleccionar «la segunda», conserva el orden de los IDs mostrados y envía el segundo a `item`. Esa operación devuelve el mismo formato y vuelve a comprobar la visibilidad. Una ficha eliminada o que dejó de estar disponible devuelve `empty`.

**Cero coincidencias · HTTP 200**

```json
{"status":"empty","returned_count":0,"items":[]}
```

**Clave incorrecta · HTTP 401**

```json
{"status":"error","returned_count":0,"items":[],"error":{"code":"unauthorized"}}
```

Las entradas inválidas devuelven HTTP 400 con `invalid_query` o `unsupported_post_type`. Un fallo interno devuelve HTTP 500 con `search_failed`. Botzy elige el destino por `status` y gestiona también los fallos de transporte. `returned_count` cuenta las fichas entregadas. Esta versión omite `total_matches` porque ordena una selección acotada de candidatos.

## Verificación local

Desde la carpeta del plugin, ejecuta `php tests/search.php` en una instalación local con WordPress y MySQL o MariaDB. La prueba usa el enrutador REST real, una clave de prueba y una tabla temporal para su catálogo.
