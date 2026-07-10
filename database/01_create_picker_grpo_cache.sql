USE [WHPOKAYOKE];
GO

SET NOCOUNT ON;
GO

IF OBJECT_ID(N'dbo.PickerGrpoReceiptCache', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.PickerGrpoReceiptCache
    (
        CacheID          BIGINT IDENTITY(1,1) NOT NULL
            CONSTRAINT PK_PickerGrpoReceiptCache PRIMARY KEY,
        PoDocEntry       INT NOT NULL,
        PoDocNum         INT NULL,
        PoDocDate        DATE NULL,
        PoLineNum        INT NOT NULL,
        VendorCode       NVARCHAR(50) NULL,
        VendorName       NVARCHAR(200) NULL,
        GrpoDocEntry     INT NOT NULL,
        GrpoDocNum       INT NULL,
        GrpoDocDate      DATE NOT NULL,
        GrpoLineNum      INT NOT NULL,
        ItemCode         NVARCHAR(100) NOT NULL,
        PartName         NVARCHAR(300) NULL,
        LotNo            NVARCHAR(100) NOT NULL
            CONSTRAINT DF_PickerGrpoReceiptCache_LotNo DEFAULT (N''),
        ReceivedQty      DECIMAL(19,6) NOT NULL
            CONSTRAINT DF_PickerGrpoReceiptCache_ReceivedQty DEFAULT (0),
        GrpoLineQty      DECIMAL(19,6) NOT NULL
            CONSTRAINT DF_PickerGrpoReceiptCache_GrpoLineQty DEFAULT (0),
        OrderedQty       DECIMAL(19,6) NOT NULL
            CONSTRAINT DF_PickerGrpoReceiptCache_OrderedQty DEFAULT (0),
        Uom              NVARCHAR(50) NULL,
        PoWarehouse      NVARCHAR(20) NULL,
        GrpoWarehouse    NVARCHAR(20) NULL,
        SyncedAt         DATETIME2(0) NOT NULL
            CONSTRAINT DF_PickerGrpoReceiptCache_SyncedAt DEFAULT (SYSDATETIME())
    );
END;
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.PickerGrpoReceiptCache')
      AND name = N'UX_PickerGrpoReceiptCache_Source'
)
BEGIN
    CREATE UNIQUE INDEX UX_PickerGrpoReceiptCache_Source
        ON dbo.PickerGrpoReceiptCache
        (GrpoDocEntry, GrpoLineNum, ItemCode, LotNo);
END;
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.PickerGrpoReceiptCache')
      AND name = N'IX_PickerGrpoReceiptCache_ReportDate'
)
BEGIN
    CREATE INDEX IX_PickerGrpoReceiptCache_ReportDate
        ON dbo.PickerGrpoReceiptCache
        (GrpoDocDate DESC, GrpoDocNum DESC, GrpoLineNum ASC)
        INCLUDE (PoDocNum, VendorCode, VendorName, ItemCode, PartName, LotNo,
                 ReceivedQty, GrpoLineQty, OrderedQty, Uom,
                 PoWarehouse, GrpoWarehouse, SyncedAt);
END;
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.PickerGrpoReceiptCache')
      AND name = N'IX_PickerGrpoReceiptCache_ItemCode'
)
BEGIN
    CREATE INDEX IX_PickerGrpoReceiptCache_ItemCode
        ON dbo.PickerGrpoReceiptCache(ItemCode, GrpoDocDate DESC);
END;
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.PickerGrpoReceiptCache')
      AND name = N'IX_PickerGrpoReceiptCache_LotNo'
)
BEGIN
    CREATE INDEX IX_PickerGrpoReceiptCache_LotNo
        ON dbo.PickerGrpoReceiptCache(LotNo, GrpoDocDate DESC);
END;
GO

IF OBJECT_ID(N'dbo.PickerGrpoCacheStatus', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.PickerGrpoCacheStatus
    (
        StatusID          TINYINT NOT NULL
            CONSTRAINT PK_PickerGrpoCacheStatus PRIMARY KEY,
        LastStartedAt     DATETIME2(0) NULL,
        LastSuccessfulAt  DATETIME2(0) NULL,
        LastFinishedAt    DATETIME2(0) NULL,
        LastStatus        VARCHAR(20) NOT NULL,
        LastMessage       NVARCHAR(1000) NULL,
        LastRowCount      INT NULL,
        WindowDateFrom    DATE NULL,
        WindowDateTo      DATE NULL
    );

    INSERT INTO dbo.PickerGrpoCacheStatus
        (StatusID, LastStatus, LastMessage)
    VALUES
        (1, 'NEVER_RUN', N'GRPO cache synchronization has not run yet.');
END;
GO

SELECT
    OBJECT_NAME(object_id) AS TableName,
    name AS IndexName
FROM sys.indexes
WHERE object_id IN
(
    OBJECT_ID(N'dbo.PickerGrpoReceiptCache'),
    OBJECT_ID(N'dbo.PickerGrpoCacheStatus')
)
ORDER BY TableName, IndexName;
GO
