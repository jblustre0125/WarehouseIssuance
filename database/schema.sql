-- Run this on WHPOKAYOKE database.
-- This app only READS SAP B1. All traceability writes go to WHPOKAYOKE.

IF OBJECT_ID('dbo.AppUsers', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.AppUsers (
        UserID INT IDENTITY(1,1) PRIMARY KEY,
        Username NVARCHAR(60) NOT NULL UNIQUE,
        FullName NVARCHAR(120) NULL,
        PasswordHash NVARCHAR(255) NOT NULL,
        RoleName NVARCHAR(20) NOT NULL CHECK (RoleName IN ('picker','issuer','requestor','receiver','admin')),
        ReceiverArea NVARCHAR(80) NULL,
        RequestorSection NVARCHAR(80) NULL,
        DeviceHostname NVARCHAR(120) NULL,
        DeviceIPAddress NVARCHAR(45) NULL,
        LastLoginHostname NVARCHAR(120) NULL,
        LastLoginIPAddress NVARCHAR(45) NULL,
        LastLoginAt DATETIME NULL,
        IsActive BIT NOT NULL DEFAULT 1,
        CreatedAt DATETIME NOT NULL DEFAULT GETDATE(),
        UpdatedAt DATETIME NULL
    );
END
GO

IF OBJECT_ID('dbo.AppUsers', 'U') IS NOT NULL
BEGIN
    DECLARE @roleColumnId INT = COLUMNPROPERTY(OBJECT_ID('dbo.AppUsers'), 'RoleName', 'ColumnId');
    DECLARE @dropAppUserRoleConstraintsSql NVARCHAR(MAX) = N'';

    SELECT @dropAppUserRoleConstraintsSql = @dropAppUserRoleConstraintsSql
        + N'ALTER TABLE dbo.AppUsers DROP CONSTRAINT ' + QUOTENAME(cc.name) + N';'
    FROM sys.check_constraints cc
    WHERE cc.parent_object_id = OBJECT_ID('dbo.AppUsers')
      AND (
            cc.parent_column_id = @roleColumnId
            OR cc.definition LIKE '%RoleName%'
            OR (
                cc.definition LIKE '%issuer%'
                AND cc.definition LIKE '%receiver%'
                AND cc.definition LIKE '%admin%'
            )
      );

    IF @dropAppUserRoleConstraintsSql <> N''
    BEGIN
        EXEC sp_executesql @dropAppUserRoleConstraintsSql;
    END

    ALTER TABLE dbo.AppUsers ADD CONSTRAINT CK_AppUsers_RoleName CHECK (RoleName IN ('picker','issuer','requestor','receiver','admin'));
END
GO

IF COL_LENGTH('dbo.AppUsers', 'RequestorSection') IS NULL
BEGIN
    ALTER TABLE dbo.AppUsers ADD RequestorSection NVARCHAR(80) NULL;
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.AppUsers WHERE Username = 'admin')
BEGIN
    -- default password: admin123. Change immediately.
    INSERT INTO dbo.AppUsers (Username, FullName, PasswordHash, RoleName, IsActive)
    VALUES ('admin', 'System Administrator', '$2y$12$lZKQ3hRE.OhpWx37Ve4Vs.3CvMyfJ0G7qLALe3mZ4UJTcsjWqaYsO', 'admin', 1);
END
GO

IF OBJECT_ID('dbo.RawmatTraceHeader', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RawmatTraceHeader (
        TraceID INT IDENTITY(1,1) PRIMARY KEY,
        TraceNo NVARCHAR(80) NOT NULL UNIQUE,
        ITRNumber NVARCHAR(80) NULL,
        SAP_IT_DocEntry INT NULL,
        SAP_IT_DocNum INT NULL,
        DestinationArea NVARCHAR(80) NULL,
        Status NVARCHAR(30) NOT NULL DEFAULT 'ISSUED',
        CreatedByUserID INT NULL,
        CreatedByUsername NVARCHAR(60) NULL,
        CreatedAt DATETIME NOT NULL DEFAULT GETDATE(),
        DeviceHostname NVARCHAR(120) NULL,
        DeviceIPAddress NVARCHAR(45) NULL
    );
END
ELSE
BEGIN
    IF COL_LENGTH('dbo.RawmatTraceHeader', 'SAP_IT_DocEntry') IS NULL ALTER TABLE dbo.RawmatTraceHeader ADD SAP_IT_DocEntry INT NULL;
    IF COL_LENGTH('dbo.RawmatTraceHeader', 'SAP_IT_DocNum') IS NULL ALTER TABLE dbo.RawmatTraceHeader ADD SAP_IT_DocNum INT NULL;
    IF COL_LENGTH('dbo.RawmatTraceHeader', 'DestinationArea') IS NULL ALTER TABLE dbo.RawmatTraceHeader ADD DestinationArea NVARCHAR(80) NULL;
END
GO

IF OBJECT_ID('dbo.RawmatTraceLines', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RawmatTraceLines (
        TraceLineID INT IDENTITY(1,1) PRIMARY KEY,
        TraceID INT NOT NULL,
        ItemCode NVARCHAR(50) NOT NULL,
        PartName NVARCHAR(255) NOT NULL,
        LotNo NVARCHAR(80) NOT NULL,
        WarehouseLotNo NVARCHAR(80) NULL,
        IssuedQty DECIMAL(18,3) NOT NULL,
        ReceivedLotNo NVARCHAR(80) NULL,
        ReceivedQty DECIMAL(18,3) NULL,
        VarianceQty AS (ISNULL(ReceivedQty, 0) - IssuedQty),
        EntryMethod NVARCHAR(20) NOT NULL DEFAULT 'SCAN',
        ManualReason NVARCHAR(255) NULL,
        IssuedByUsername NVARCHAR(60) NULL,
        IssuedAt DATETIME NOT NULL DEFAULT GETDATE(),
        ReceivedByUsername NVARCHAR(60) NULL,
        ReceivedAt DATETIME NULL,
        ReceiverArea NVARCHAR(80) NULL,
        Remarks NVARCHAR(255) NULL,
        VerificationStatus NVARCHAR(30) NOT NULL DEFAULT 'PENDING_RECEIVE',
        SAP_IT_DocEntry INT NULL,
        SAP_IT_DocNum INT NULL,
        SAP_IT_LineNum INT NULL,
        IssueRequestID INT NULL,
        IssueRequestLineID INT NULL,
        ReceiveToken NVARCHAR(80) NULL,
        ReceivedScanAt DATETIME NULL,
        CONSTRAINT FK_RawmatTraceLines_Header FOREIGN KEY (TraceID) REFERENCES dbo.RawmatTraceHeader(TraceID)
    );
END
ELSE
BEGIN
    IF COL_LENGTH('dbo.RawmatTraceLines', 'SAP_IT_DocEntry') IS NULL ALTER TABLE dbo.RawmatTraceLines ADD SAP_IT_DocEntry INT NULL;
    IF COL_LENGTH('dbo.RawmatTraceLines', 'SAP_IT_DocNum') IS NULL ALTER TABLE dbo.RawmatTraceLines ADD SAP_IT_DocNum INT NULL;
    IF COL_LENGTH('dbo.RawmatTraceLines', 'SAP_IT_LineNum') IS NULL ALTER TABLE dbo.RawmatTraceLines ADD SAP_IT_LineNum INT NULL;
    IF COL_LENGTH('dbo.RawmatTraceLines', 'IssueRequestID') IS NULL ALTER TABLE dbo.RawmatTraceLines ADD IssueRequestID INT NULL;
    IF COL_LENGTH('dbo.RawmatTraceLines', 'IssueRequestLineID') IS NULL ALTER TABLE dbo.RawmatTraceLines ADD IssueRequestLineID INT NULL;
    IF COL_LENGTH('dbo.RawmatTraceLines', 'ReceiveToken') IS NULL ALTER TABLE dbo.RawmatTraceLines ADD ReceiveToken NVARCHAR(80) NULL;
    IF COL_LENGTH('dbo.RawmatTraceLines', 'ReceivedScanAt') IS NULL ALTER TABLE dbo.RawmatTraceLines ADD ReceivedScanAt DATETIME NULL;
    IF COL_LENGTH('dbo.RawmatTraceLines', 'WarehouseLotNo') IS NULL ALTER TABLE dbo.RawmatTraceLines ADD WarehouseLotNo NVARCHAR(80) NULL;
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'UX_RawmatTraceLines_ReceiveToken' AND object_id = OBJECT_ID('dbo.RawmatTraceLines'))
BEGIN
    CREATE UNIQUE INDEX UX_RawmatTraceLines_ReceiveToken ON dbo.RawmatTraceLines(ReceiveToken) WHERE ReceiveToken IS NOT NULL;
END
GO

IF OBJECT_ID('dbo.IssuanceTransactions', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.IssuanceTransactions (
        TransactionID INT IDENTITY(1,1) PRIMARY KEY,
        TraceNo NVARCHAR(80) NULL,
        ItemCode NVARCHAR(50) NOT NULL,
        PartName NVARCHAR(255) NOT NULL,
        Quantity DECIMAL(18,3) NOT NULL,
        LotNo NVARCHAR(80) NOT NULL,
        WarehouseLotNo NVARCHAR(80) NULL,
        ITRNumber NVARCHAR(80) NULL,
        IssuedByUserID INT NULL,
        IssuedByUsername NVARCHAR(60) NULL,
        DeviceHostname NVARCHAR(120) NULL,
        DeviceIPAddress NVARCHAR(45) NULL,
        ITRDocEntry INT NULL,
        ITRLineNum INT NULL,
        IssueRequestID INT NULL,
        IssueRequestLineID INT NULL,
        IssuedAt DATETIME NOT NULL DEFAULT GETDATE()
    );
END
ELSE IF COL_LENGTH('dbo.IssuanceTransactions', 'TraceNo') IS NULL
BEGIN
    ALTER TABLE dbo.IssuanceTransactions ADD TraceNo NVARCHAR(80) NULL;
END
GO

IF COL_LENGTH('dbo.IssuanceTransactions', 'ITRDocEntry') IS NULL ALTER TABLE dbo.IssuanceTransactions ADD ITRDocEntry INT NULL;
IF COL_LENGTH('dbo.IssuanceTransactions', 'ITRLineNum') IS NULL ALTER TABLE dbo.IssuanceTransactions ADD ITRLineNum INT NULL;
IF COL_LENGTH('dbo.IssuanceTransactions', 'IssueRequestID') IS NULL ALTER TABLE dbo.IssuanceTransactions ADD IssueRequestID INT NULL;
IF COL_LENGTH('dbo.IssuanceTransactions', 'IssueRequestLineID') IS NULL ALTER TABLE dbo.IssuanceTransactions ADD IssueRequestLineID INT NULL;
IF COL_LENGTH('dbo.IssuanceTransactions', 'WarehouseLotNo') IS NULL ALTER TABLE dbo.IssuanceTransactions ADD WarehouseLotNo NVARCHAR(80) NULL;
GO

IF OBJECT_ID('dbo.WarehouseIssueRequestHeader', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.WarehouseIssueRequestHeader (
        RequestID INT IDENTITY(1,1) PRIMARY KEY,
        RequestNo NVARCHAR(80) NOT NULL UNIQUE,
        ITRNumber NVARCHAR(80) NOT NULL,
        SAP_IT_DocEntry INT NULL,
        SAP_IT_DocNum INT NULL,
        DestinationArea NVARCHAR(80) NULL,
        NeededDate DATE NOT NULL,
        Status NVARCHAR(30) NOT NULL DEFAULT 'OPEN',
        Remarks NVARCHAR(255) NULL,
        RequestedByUserID INT NULL,
        RequestedByUsername NVARCHAR(60) NULL,
        RequestedAt DATETIME NOT NULL DEFAULT GETDATE(),
        DeviceHostname NVARCHAR(120) NULL,
        DeviceIPAddress NVARCHAR(45) NULL,
        IssuedTraceNo NVARCHAR(80) NULL,
        ClosedAt DATETIME NULL
    );
END
GO

IF COL_LENGTH('dbo.WarehouseIssueRequestHeader', 'DestinationArea') IS NULL
BEGIN
    ALTER TABLE dbo.WarehouseIssueRequestHeader ADD DestinationArea NVARCHAR(80) NULL;
END
GO

IF OBJECT_ID('dbo.WarehouseIssueRequestLines', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.WarehouseIssueRequestLines (
        RequestLineID INT IDENTITY(1,1) PRIMARY KEY,
        RequestID INT NOT NULL,
        SAP_IT_DocEntry INT NULL,
        SAP_IT_DocNum INT NULL,
        SAP_IT_LineNum INT NULL,
        ItemCode NVARCHAR(50) NOT NULL,
        PartName NVARCHAR(255) NOT NULL,
        RequestedQty DECIMAL(18,3) NOT NULL,
        IssuedQty DECIMAL(18,3) NULL,
        LotNo NVARCHAR(80) NULL,
        WarehouseLotNo NVARCHAR(80) NULL,
        Status NVARCHAR(30) NOT NULL DEFAULT 'OPEN',
        CONSTRAINT FK_WarehouseIssueRequestLines_Header FOREIGN KEY (RequestID) REFERENCES dbo.WarehouseIssueRequestHeader(RequestID)
    );
END
GO

IF COL_LENGTH('dbo.WarehouseIssueRequestLines', 'LotNo') IS NULL
BEGIN
    ALTER TABLE dbo.WarehouseIssueRequestLines ADD LotNo NVARCHAR(80) NULL;
END
GO

IF COL_LENGTH('dbo.WarehouseIssueRequestLines', 'WarehouseLotNo') IS NULL
BEGIN
    ALTER TABLE dbo.WarehouseIssueRequestLines ADD WarehouseLotNo NVARCHAR(80) NULL;
END
GO

IF OBJECT_ID('dbo.ReceivingTransactions', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.ReceivingTransactions (
        TransactionID INT IDENTITY(1,1) PRIMARY KEY,
        TraceNo NVARCHAR(80) NULL,
        ItemCode NVARCHAR(50) NOT NULL,
        PartName NVARCHAR(255) NOT NULL,
        Quantity DECIMAL(18,3) NOT NULL,
        LotNo NVARCHAR(80) NULL,
        LocationCode NVARCHAR(80) NULL,
        ModelAllocation NVARCHAR(120) NULL,
        ProcessName NVARCHAR(120) NULL,
        ReceiverArea NVARCHAR(80) NOT NULL,
        Remarks NVARCHAR(255) NULL,
        ReceivedByUserID INT NULL,
        ReceivedByUsername NVARCHAR(60) NULL,
        DeviceHostname NVARCHAR(120) NULL,
        DeviceIPAddress NVARCHAR(45) NULL,
        ReceivedAt DATETIME NOT NULL DEFAULT GETDATE()
    );
END
ELSE
BEGIN
    IF COL_LENGTH('dbo.ReceivingTransactions', 'TraceNo') IS NULL ALTER TABLE dbo.ReceivingTransactions ADD TraceNo NVARCHAR(80) NULL;
    IF COL_LENGTH('dbo.ReceivingTransactions', 'LotNo') IS NULL ALTER TABLE dbo.ReceivingTransactions ADD LotNo NVARCHAR(80) NULL;
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_RawmatTraceHeader_TraceNo' AND object_id = OBJECT_ID('dbo.RawmatTraceHeader'))
BEGIN
    CREATE INDEX IX_RawmatTraceHeader_TraceNo ON dbo.RawmatTraceHeader(TraceNo);
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_RawmatTraceLines_TraceID' AND object_id = OBJECT_ID('dbo.RawmatTraceLines'))
BEGIN
    CREATE INDEX IX_RawmatTraceLines_TraceID ON dbo.RawmatTraceLines(TraceID);
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_RawmatTraceLines_Status' AND object_id = OBJECT_ID('dbo.RawmatTraceLines'))
BEGIN
    CREATE INDEX IX_RawmatTraceLines_Status ON dbo.RawmatTraceLines(VerificationStatus);
END
GO

IF OBJECT_ID('dbo.SapDataCache', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.SapDataCache (
        CacheKey NVARCHAR(160) NOT NULL PRIMARY KEY,
        ScopeName NVARCHAR(80) NOT NULL,
        PayloadJson NVARCHAR(MAX) NOT NULL,
        CachedAt DATETIME NOT NULL DEFAULT GETDATE(),
        ExpiresAt DATETIME NOT NULL
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_SapDataCache_ScopeName' AND object_id = OBJECT_ID('dbo.SapDataCache'))
BEGIN
    CREATE INDEX IX_SapDataCache_ScopeName ON dbo.SapDataCache(ScopeName, ExpiresAt);
END
GO

IF OBJECT_ID('dbo.RawmatTraceScanPlusCache', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RawmatTraceScanPlusCache (
        CacheID INT IDENTITY(1,1) PRIMARY KEY,
        SAP_IT_DocEntry INT NOT NULL,
        SAP_IT_LineNum INT NULL,
        ItemCode NVARCHAR(50) NOT NULL,
        LotNo NVARCHAR(80) NULL,
        ReceivedLotNo NVARCHAR(80) NULL,
        ScanStatus NVARCHAR(50) NULL,
        ReceivedQty DECIMAL(18,3) NULL,
        BarcodeUser NVARCHAR(120) NULL,
        ReceivedAt DATETIME NULL,
        LastSyncedAt DATETIME NOT NULL DEFAULT GETDATE()
    );
END
GO

IF COL_LENGTH('dbo.RawmatTraceScanPlusCache', 'ReceivedLotNo') IS NULL
BEGIN
    ALTER TABLE dbo.RawmatTraceScanPlusCache ADD ReceivedLotNo NVARCHAR(80) NULL;
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_RawmatTraceScanPlusCache_Lookup' AND object_id = OBJECT_ID('dbo.RawmatTraceScanPlusCache'))
BEGIN
    CREATE INDEX IX_RawmatTraceScanPlusCache_Lookup
    ON dbo.RawmatTraceScanPlusCache(SAP_IT_DocEntry, SAP_IT_LineNum, ItemCode, LotNo, LastSyncedAt);
END
GO

IF OBJECT_ID('dbo.SapCacheSyncLog', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.SapCacheSyncLog (
        SyncID INT IDENTITY(1,1) PRIMARY KEY,
        ScopeName NVARCHAR(80) NOT NULL,
        StartedAt DATETIME NOT NULL DEFAULT GETDATE(),
        FinishedAt DATETIME NULL,
        Status NVARCHAR(30) NOT NULL DEFAULT 'RUNNING',
        Message NVARCHAR(1000) NULL,
        [RowCount] INT NULL
    );
END
GO
