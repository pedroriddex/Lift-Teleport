# Especificación del formato `.lift`

Este documento define el contrato de interoperabilidad para exportar e importar paquetes `.lift` entre versiones del plugin Lift Teleport.

## 1) Estructura mínima del paquete

Un paquete `.lift` **debe** contener:

1. `header.json`: cabecera del formato.
2. `manifest.json`: manifiesto con índice de chunks y metadatos.
3. `chunks/`: objetos chunked (`*.chunk`) para base de datos, medios y archivos.
4. `signatures/` (opcional): firma detached del manifiesto/global hash.

### 1.1 `header.json`

Campos obligatorios:

```json
{
  "format": "lift",
  "spec_version": "1.0.0",
  "created_at": "2026-02-19T12:00:00Z",
  "plugin_version": "0.1.0"
}
```

Reglas:

- `format` debe ser exactamente `lift`.
- `spec_version` usa semver (`MAJOR.MINOR.PATCH`).
- `plugin_version` identifica la versión del plugin exportador.

### 1.2 `manifest.json`

Campos mínimos:

```json
{
  "package_id": "uuid-v4",
  "spec_version": "1.0.0",
  "compatibility": {
    "min_importer_plugin_version": "0.1.0",
    "max_importer_plugin_version": "1.x"
  },
  "environment": {
    "wordpress_version": "6.8.1",
    "php_version": "8.2.12",
    "charset": "utf8mb4",
    "active_plugins": [
      "akismet/akismet.php",
      "woocommerce/woocommerce.php"
    ]
  },
  "chunks": [
    {
      "id": "db-schema-0001",
      "type": "database",
      "role": "schema",
      "path": "chunks/db-schema-0001.chunk",
      "size": 8192,
      "hash": "sha256:..."
    }
  ],
  "integrity": {
    "algorithm": "sha256",
    "global_hash": "sha256:..."
  },
  "signature": {
    "enabled": false,
    "algorithm": null,
    "key_id": null,
    "manifest_signature": null
  }
}
```

Reglas:

- `chunks` debe tener al menos 1 entrada.
- Cada chunk debe tener `id`, `type`, `path`, `size`, `hash`.
- `hash` por chunk usa prefijo de algoritmo (`sha256:<hex>`).

### 1.3 Metadatos de entorno origen

`environment` debe reportar al menos:

- versión de WordPress (`wordpress_version`)
- versión de PHP (`php_version`)
- charset (`charset`)
- plugins activos (`active_plugins`)

## 2) Compatibilidad hacia atrás/adelante

La compatibilidad se define por `spec_version` y `compatibility`.

- **Backward compatible**: cambios `MINOR` y `PATCH` no rompen imports en importadores con mismo `MAJOR`.
- **Breaking changes**: cambios `MAJOR` requieren importador con mismo `MAJOR`.
- Importador debe rechazar paquetes si:
  - `spec_version` tiene `MAJOR` distinto.
  - su versión de plugin es `< min_importer_plugin_version`.
  - su versión de plugin es `> max_importer_plugin_version` cuando éste sea exacto semver.

Recomendación:

- Usar `max_importer_plugin_version` tipo rango flexible (`1.x`) para permitir evolución dentro del major.

## 3) Integridad y firma

### 3.1 Integridad

- Cada chunk incluye `hash` individual (`sha256`).
- `integrity.global_hash` se calcula sobre la concatenación determinística de:
  - bytes de cada chunk ordenados por `id`, y
  - `manifest.json` canónico sin el bloque `signature.manifest_signature`.

### 3.2 Firma opcional

Si `signature.enabled = true`:

- `signature.algorithm` (ejemplo: `ed25519`).
- `signature.key_id` para identificar clave pública.
- `signature.manifest_signature` con firma detached de `global_hash`.
- Importador debe verificar firma antes de restaurar.

## 4) Serialización de datos

### 4.1 Base de datos

- Serializar por lotes en chunks tipo `database`.
- Separar `schema` y `data` por `role`.
- Mantener codificación UTF-8 y escaping compatible con MySQL/MariaDB.
- Incluir `db/table-map.json` (opcional recomendado) para mapear tablas incluidas.

### 4.2 Medios grandes

- Chunks tipo `media` con tamaño recomendado entre 8MB y 64MB.
- Definir `media/index.json` con ruta relativa, tamaño y hash de cada archivo original.
- Permitir reintentos idempotentes por chunk en importación.

### 4.3 Archivos especiales

- **Symlinks**: no materializar por defecto; serializar como metadato (`type=file`, `special=symlink`, `target=...`).
- **Permisos**: preservar modo POSIX (`0644`, `0755`) en campo `mode`; en sistemas no POSIX degradar con advertencia.
- **Propietario/grupo**: opcional, nunca obligatorio para restauración.

## 5) Validación de esquema (exporter/importer)

### 5.1 Exporter

Antes de empaquetar:

- validar `header.json` y `manifest.json` contra esquema.
- rechazar chunk sin hash/tamaño/ruta.
- rechazar versión semver inválida.

### 5.2 Importer

Antes de escribir en disco o DB:

- validar estructura mínima del paquete.
- validar compatibilidad de versiones.
- validar hash por chunk y hash global.
- validar firma si está habilitada.

### 5.3 Mensajes diagnósticos

Errores deben incluir:

- código machine-readable (`invalid_manifest`, `unsupported_spec_major`, `chunk_hash_mismatch`).
- mensaje humano claro.
- contexto (`chunk_id`, `expected`, `actual`) cuando aplique.

Ejemplo:

```json
{
  "code": "chunk_hash_mismatch",
  "message": "El chunk media-0042 no coincide con el hash declarado.",
  "context": {
    "chunk_id": "media-0042",
    "expected": "sha256:abc...",
    "actual": "sha256:def..."
  }
}
```
