# Lift Teleport (MVP scaffold)

Primer esqueleto del plugin **Lift Teleport** para WordPress.

## Incluye

- Estructura base del plugin y carga de assets.
- Pantalla de administración con enfoque de producto para Lift.
- Prototipo visual de importación por drag & drop de archivos `.lift`.
- Simulación de progreso para validar UX mientras se construye el motor real de migración.
- Especificación inicial del formato `.lift` en `docs/lift-format.md`.
- Validadores de esquema para exporter/importer con errores diagnósticos (`WP_Error`).

## Próximos pasos sugeridos

1. Motor real de exportación incremental por lotes.
2. Formato `.lift` con manifiesto, checksum y chunks.
3. Motor de importación con rollback y reintentos automáticos.
4. Logs de migración y diagnóstico post-importación.
