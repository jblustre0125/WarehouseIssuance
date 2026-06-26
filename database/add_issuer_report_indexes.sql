IF COL_LENGTH('dbo.IssuanceTransactions', 'WarehouseLotNo') IS NULL
BEGIN
    ALTER TABLE dbo.IssuanceTransactions ADD WarehouseLotNo NVARCHAR(80) NULL;
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_RawmatTraceLines_ReportTraceItemLot' AND object_id = OBJECT_ID('dbo.RawmatTraceLines'))
BEGIN
    CREATE INDEX IX_RawmatTraceLines_ReportTraceItemLot
    ON dbo.RawmatTraceLines(TraceID, ItemCode, LotNo, TraceLineID);
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_IssuanceTransactions_ReportDate' AND object_id = OBJECT_ID('dbo.IssuanceTransactions'))
BEGIN
    CREATE INDEX IX_IssuanceTransactions_ReportDate
    ON dbo.IssuanceTransactions(IssuedAt DESC, TransactionID DESC)
    INCLUDE (TraceNo, ItemCode, PartName, Quantity, LotNo, WarehouseLotNo, ITRNumber, ITRDocEntry, ITRLineNum, IssuedByUsername, DeviceHostname, DeviceIPAddress);
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_IssuanceTransactions_ReportUserDate' AND object_id = OBJECT_ID('dbo.IssuanceTransactions'))
BEGIN
    CREATE INDEX IX_IssuanceTransactions_ReportUserDate
    ON dbo.IssuanceTransactions(IssuedByUsername, IssuedAt DESC, TransactionID DESC)
    INCLUDE (TraceNo, ItemCode, PartName, Quantity, LotNo, WarehouseLotNo, ITRNumber, ITRDocEntry, ITRLineNum, DeviceHostname, DeviceIPAddress);
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_WarehouseIssueRequestHeader_IssuedTraceNo' AND object_id = OBJECT_ID('dbo.WarehouseIssueRequestHeader'))
BEGIN
    CREATE INDEX IX_WarehouseIssueRequestHeader_IssuedTraceNo
    ON dbo.WarehouseIssueRequestHeader(IssuedTraceNo, RequestedAt DESC)
    INCLUDE (RequestNo, RequestedByUsername, Status);
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_WarehouseIssueRequestLines_ReportRequestItemLot' AND object_id = OBJECT_ID('dbo.WarehouseIssueRequestLines'))
BEGIN
    CREATE INDEX IX_WarehouseIssueRequestLines_ReportRequestItemLot
    ON dbo.WarehouseIssueRequestLines(RequestID, ItemCode, LotNo, RequestLineID)
    INCLUDE (SAP_IT_DocEntry, SAP_IT_LineNum, RequestedQty, IssuedQty, Status);
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_WarehouseIssueRequestLines_ReportSapLine' AND object_id = OBJECT_ID('dbo.WarehouseIssueRequestLines'))
BEGIN
    CREATE INDEX IX_WarehouseIssueRequestLines_ReportSapLine
    ON dbo.WarehouseIssueRequestLines(SAP_IT_DocEntry, SAP_IT_LineNum, ItemCode, LotNo, RequestLineID)
    INCLUDE (RequestID, RequestedQty, IssuedQty, Status);
END
GO
