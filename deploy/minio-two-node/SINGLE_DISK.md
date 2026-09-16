# Producción con un disco por nodo

Este perfil permite que los dos nodos de aplicación compartan el almacenamiento
sin requerir volúmenes independientes. Usa dos directorios lógicos en el mismo
filesystem de cada nodo y crea cuatro endpoints MinIO en total con paridad
`EC:2`.

No ofrece redundancia frente a la falla física del disco: una falla del disco
de un host elimina ambos fragmentos que ese host contiene. Mantenga un respaldo
externo, verificado y recuperable de los buckets y de SQL Server. No describa
esta topología como alta disponibilidad de almacenamiento.

## Inicio

En cada nodo, configure los hostnames `pchquit01dweb14.fj.local` y `pchquit01dweb14.fj.local-02`, TLS,
las credenciales de MinIO y las rutas persistentes. Cree las rutas antes de
iniciar el servicio.

```bash
cp minio.single-disk.env.example minio.single-disk.env
# En nodo 2 establezca MINIO_NODE_NAME=pchquit01dweb14.fj.local-02.
mkdir -p /var/lib/academic-minio/data1 /var/lib/academic-minio/data2
./preflight-single-disk.sh minio.single-disk.env
docker compose --env-file minio.single-disk.env -f compose-single-disk.yml up -d
```

Inicie ambos nodos durante la misma ventana. La aplicación sigue usando
`STORAGE_DRIVER=s3` y el mismo endpoint, buckets y credenciales en ambos
nodos. Ejecute `php bin/storage-init.php` una sola vez para crear los buckets.

No convierta directamente una instalación creada con `compose.yml` (ocho
discos) a este perfil, ni a la inversa. Cree un clúster limpio y migre los
objetos mediante una copia/restauración controlada.

## Operación ante fallos

Al caer un nodo, la lectura de objetos existentes puede mantenerse, pero las
escrituras se bloquean hasta recuperar el quorum. Si se pierde el disco de un
nodo, restaure los datos desde el respaldo antes de reabrir las cargas.
