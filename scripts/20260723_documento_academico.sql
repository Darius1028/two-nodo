/*
 * Persistencia y auditoría de los PDF enviados al Repositorio Documental.
 * SQL Server. Idempotente: puede ejecutarse nuevamente sin recrear objetos.
 */

IF NOT EXISTS (
    SELECT 1 FROM sys.tables t
    JOIN sys.schemas s ON s.schema_id = t.schema_id
    WHERE s.name = 'Academico' AND t.name = 'DocumentoAcademico'
)
BEGIN
    CREATE TABLE [Academico].[DocumentoAcademico] (
        [idDocumento] INT IDENTITY(1,1) NOT NULL,
        [idRecordAcademico] INT NOT NULL,
        [uuidRepositorio] NVARCHAR(100) NOT NULL,
        [nombreArchivo] NVARCHAR(255) NOT NULL,
        [estado] NVARCHAR(1) NOT NULL
            CONSTRAINT [DF_DocumentoAcademico_estado] DEFAULT 'A',
        [idPersonaCrea] INT NOT NULL
            CONSTRAINT [DF_DocumentoAcademico_idPersonaCrea] DEFAULT 0,
        [fechaCrea] DATETIME NOT NULL
            CONSTRAINT [DF_DocumentoAcademico_fechaCrea] DEFAULT GETDATE(),
        [ipCrea] NVARCHAR(45) NOT NULL
            CONSTRAINT [DF_DocumentoAcademico_ipCrea] DEFAULT '',
        [equipoCrea] NVARCHAR(50) NOT NULL
            CONSTRAINT [DF_DocumentoAcademico_equipoCrea] DEFAULT '',
        [idPersonaModifica] INT NOT NULL
            CONSTRAINT [DF_DocumentoAcademico_idPersonaModifica] DEFAULT 0,
        [fechaModifica] DATETIME NOT NULL
            CONSTRAINT [DF_DocumentoAcademico_fechaModifica] DEFAULT GETDATE(),
        [ipModifica] NVARCHAR(45) NOT NULL
            CONSTRAINT [DF_DocumentoAcademico_ipModifica] DEFAULT '',
        [equipoModifica] NVARCHAR(50) NOT NULL
            CONSTRAINT [DF_DocumentoAcademico_equipoModifica] DEFAULT '',
        [motivoModifica] NVARCHAR(250) NOT NULL
            CONSTRAINT [DF_DocumentoAcademico_motivoModifica] DEFAULT '',
        CONSTRAINT [PK_Academico_DocumentoAcademico]
            PRIMARY KEY CLUSTERED ([idDocumento]),
        CONSTRAINT [FK_DocumentoAcademico_RecordAcademico]
            FOREIGN KEY ([idRecordAcademico])
            REFERENCES [Academico].[RecordAcademico] ([id])
    );
END;
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = 'UX_DocumentoAcademico_uuidRepositorio'
      AND object_id = OBJECT_ID(N'[Academico].[DocumentoAcademico]')
)
    CREATE UNIQUE NONCLUSTERED INDEX [UX_DocumentoAcademico_uuidRepositorio]
        ON [Academico].[DocumentoAcademico] ([uuidRepositorio]);
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = 'IX_DocumentoAcademico_idRecordAcademico'
      AND object_id = OBJECT_ID(N'[Academico].[DocumentoAcademico]')
)
    CREATE NONCLUSTERED INDEX [IX_DocumentoAcademico_idRecordAcademico]
        ON [Academico].[DocumentoAcademico] ([idRecordAcademico]);
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.tables t
    JOIN sys.schemas s ON s.schema_id = t.schema_id
    WHERE s.name = 'AcademicoAUD' AND t.name = 'DocumentoAcademico_AUD'
)
BEGIN
    CREATE TABLE [AcademicoAUD].[DocumentoAcademico_AUD] (
        [idDocumento] INT NOT NULL,
        [REV] INT NOT NULL,
        [REVTYPE] SMALLINT NULL,
        [idRecordAcademico] INT NULL,
        [uuidRepositorio] NVARCHAR(100) NULL,
        [nombreArchivo] NVARCHAR(255) NULL,
        [estado] NVARCHAR(1) NULL,
        [idPersonaCrea] INT NULL,
        [fechaCrea] DATETIME NULL,
        [ipCrea] NVARCHAR(45) NULL,
        [equipoCrea] NVARCHAR(50) NULL,
        [idPersonaModifica] INT NULL,
        [fechaModifica] DATETIME NULL,
        [ipModifica] NVARCHAR(45) NULL,
        [equipoModifica] NVARCHAR(50) NULL,
        [motivoModifica] NVARCHAR(250) NULL,
        CONSTRAINT [PK_AcademicoAUD_DocumentoAcademico_AUD]
            PRIMARY KEY CLUSTERED ([idDocumento], [REV]),
        CONSTRAINT [FK_DocumentoAcademico_AUD_REVINFO]
            FOREIGN KEY ([REV]) REFERENCES [AUD].[REVINFO] ([REV])
    );
END;
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = 'IX_DocumentoAcademico_AUD_idDocumento'
      AND object_id = OBJECT_ID(N'[AcademicoAUD].[DocumentoAcademico_AUD]')
)
    CREATE NONCLUSTERED INDEX [IX_DocumentoAcademico_AUD_idDocumento]
        ON [AcademicoAUD].[DocumentoAcademico_AUD] ([idDocumento]);
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = 'IX_DocumentoAcademico_AUD_idRecordAcademico'
      AND object_id = OBJECT_ID(N'[AcademicoAUD].[DocumentoAcademico_AUD]')
)
    CREATE NONCLUSTERED INDEX [IX_DocumentoAcademico_AUD_idRecordAcademico]
        ON [AcademicoAUD].[DocumentoAcademico_AUD] ([idRecordAcademico]);
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = 'IX_DocumentoAcademico_AUD_uuidRepositorio'
      AND object_id = OBJECT_ID(N'[AcademicoAUD].[DocumentoAcademico_AUD]')
)
    CREATE NONCLUSTERED INDEX [IX_DocumentoAcademico_AUD_uuidRepositorio]
        ON [AcademicoAUD].[DocumentoAcademico_AUD] ([uuidRepositorio]);
GO
