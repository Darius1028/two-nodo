SET XACT_ABORT ON;
BEGIN TRY
    BEGIN TRANSACTION;

    IF OBJECT_ID(N'Academico.ImportJob', N'U') IS NULL
    BEGIN
        THROW 50001, 'No existe Academico.ImportJob. Aplique primero el esquema base.', 1;
    END;

IF COL_LENGTH(N'Academico.ImportJob', N'storageBucket') IS NULL
    ALTER TABLE Academico.ImportJob ADD storageBucket NVARCHAR(63) NULL;
IF COL_LENGTH(N'Academico.ImportJob', N'objectKey') IS NULL
    ALTER TABLE Academico.ImportJob ADD objectKey NVARCHAR(1024) NULL;
IF COL_LENGTH(N'Academico.ImportJob', N'objectVersionId') IS NULL
    ALTER TABLE Academico.ImportJob ADD objectVersionId NVARCHAR(255) NULL;
IF COL_LENGTH(N'Academico.ImportJob', N'objectETag') IS NULL
    ALTER TABLE Academico.ImportJob ADD objectETag VARCHAR(128) NULL;
IF COL_LENGTH(N'Academico.ImportJob', N'sha256') IS NULL
    ALTER TABLE Academico.ImportJob ADD sha256 CHAR(64) NULL;
IF COL_LENGTH(N'Academico.ImportJob', N'tamanoBytes') IS NULL
    ALTER TABLE Academico.ImportJob ADD tamanoBytes BIGINT NULL;
IF COL_LENGTH(N'Academico.ImportJob', N'intentos') IS NULL
    ALTER TABLE Academico.ImportJob
        ADD intentos INT NOT NULL CONSTRAINT DF_ImportJob_intentos DEFAULT (0) WITH VALUES;
IF COL_LENGTH(N'Academico.ImportJob', N'proximoIntento') IS NULL
    ALTER TABLE Academico.ImportJob ADD proximoIntento DATETIME NULL;
IF COL_LENGTH(N'Academico.ImportJob', N'workerId') IS NULL
    ALTER TABLE Academico.ImportJob ADD workerId VARCHAR(100) NULL;

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'Academico.ImportJob')
      AND name = N'IX_ImportJob_Cola'
)
    CREATE INDEX IX_ImportJob_Cola
        ON Academico.ImportJob (estado, proximoIntento, id)
        INCLUDE (objectKey, storageBucket);

IF OBJECT_ID(N'Academico.ConfiguracionAplicacion', N'U') IS NULL
BEGIN
    CREATE TABLE Academico.ConfiguracionAplicacion (
        clave NVARCHAR(100) NOT NULL
            CONSTRAINT PK_ConfiguracionAplicacion PRIMARY KEY,
        valorJson NVARCHAR(MAX) NOT NULL,
        fechaModifica DATETIME2(3) NOT NULL
            CONSTRAINT DF_ConfiguracionAplicacion_fecha DEFAULT (SYSDATETIME())
    );
END;

IF NOT EXISTS (SELECT 1 FROM Academico.ConfiguracionAplicacion WHERE clave = N'qr_enabled')
    INSERT INTO Academico.ConfiguracionAplicacion (clave, valorJson)
    VALUES (N'qr_enabled', N'true');

IF OBJECT_ID(N'Academico.HistorialAplicacion', N'U') IS NULL
BEGIN
    CREATE TABLE Academico.HistorialAplicacion (
        id BIGINT IDENTITY(1,1) NOT NULL
            CONSTRAINT PK_HistorialAplicacion PRIMARY KEY,
        fecha DATETIME2(3) NOT NULL
            CONSTRAINT DF_HistorialAplicacion_fecha DEFAULT (SYSDATETIME()),
        accion NVARCHAR(100) NOT NULL,
        detalle NVARCHAR(2000) NOT NULL,
        nodo VARCHAR(100) NOT NULL
    );
    CREATE INDEX IX_HistorialAplicacion_fecha
        ON Academico.HistorialAplicacion (fecha DESC, id DESC);
END;

IF OBJECT_ID(N'Academico.AlertaSeguridad', N'U') IS NULL
BEGIN
    CREATE TABLE Academico.AlertaSeguridad (
        id BIGINT IDENTITY(1,1) NOT NULL
            CONSTRAINT PK_AlertaSeguridad PRIMARY KEY,
        fecha DATETIME2(3) NOT NULL
            CONSTRAINT DF_AlertaSeguridad_fecha DEFAULT (SYSDATETIME()),
        nivel TINYINT NOT NULL,
        tipo NVARCHAR(100) NOT NULL,
        datosJson NVARCHAR(MAX) NOT NULL,
        nodo VARCHAR(100) NOT NULL,
        CONSTRAINT CK_AlertaSeguridad_nivel CHECK (nivel BETWEEN 1 AND 10)
    );
    CREATE INDEX IX_AlertaSeguridad_fecha
        ON Academico.AlertaSeguridad (fecha DESC, id DESC);
END;

IF OBJECT_ID(N'Academico.Sesion', N'U') IS NULL
BEGIN
    CREATE TABLE Academico.Sesion (
        idSesion VARCHAR(128) NOT NULL
            CONSTRAINT PK_Sesion PRIMARY KEY,
        datos VARBINARY(MAX) NOT NULL,
        expiraEn DATETIME2(3) NOT NULL,
        actualizadaEn DATETIME2(3) NOT NULL
            CONSTRAINT DF_Sesion_actualizadaEn DEFAULT (SYSUTCDATETIME())
    );
END;

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'Academico.Sesion')
      AND name = N'IX_Sesion_expiraEn'
)
    CREATE INDEX IX_Sesion_expiraEn ON Academico.Sesion (expiraEn);

IF OBJECT_ID(N'Academico.RateLimit', N'U') IS NULL
BEGIN
    CREATE TABLE Academico.RateLimit (
        clave CHAR(64) NOT NULL
            CONSTRAINT PK_RateLimit PRIMARY KEY,
        contador INT NOT NULL,
        expiraEn DATETIME2(3) NOT NULL,
        actualizadaEn DATETIME2(3) NOT NULL
            CONSTRAINT DF_RateLimit_actualizadaEn DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT CK_RateLimit_contador CHECK (contador > 0)
    );
END;

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'Academico.RateLimit')
      AND name = N'IX_RateLimit_expiraEn'
)
    CREATE INDEX IX_RateLimit_expiraEn ON Academico.RateLimit (expiraEn);

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF XACT_STATE() <> 0
        ROLLBACK TRANSACTION;
    THROW;
END CATCH;
