-- Run this on WHPOKAYOKE to allow the universal warehouse role in AppUsers.

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

    ALTER TABLE dbo.AppUsers
    ADD CONSTRAINT CK_AppUsers_RoleName
    CHECK (RoleName IN ('warehouse','picker','issuer','requestor','receiver','sap_encoder','admin'));
END
GO
