-- Seed RawMaterialItemLocations from JUNE 2026 RAW MATERIALS MONITORING.
-- Run on WHPOKAYOKE after database/schema.sql. Duplicate SAP codes keep the last row from the source file.

IF OBJECT_ID('dbo.RawMaterialItemLocations', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RawMaterialItemLocations (
        ItemCode NVARCHAR(50) NOT NULL PRIMARY KEY,
        PartsCode NVARCHAR(120) NULL,
        ItemName NVARCHAR(255) NULL,
        LocationCode NVARCHAR(120) NULL,
        IsActive BIT NOT NULL DEFAULT 1,
        CreatedAt DATETIME NOT NULL DEFAULT GETDATE(),
        UpdatedAt DATETIME NULL,
        UpdatedByUsername NVARCHAR(60) NULL
    );
END
GO

MERGE dbo.RawMaterialItemLocations AS T
USING (
    SELECT N'00000000404' AS ItemCode, N'PVC TAPE (0.10X19X25 B)' AS PartsCode, N'PVC TAPE' AS ItemName, N'D8' AS LocationCode
    UNION ALL
    SELECT N'00000000391' AS ItemCode, N'0.13X10X20 GR' AS PartsCode, N'PVC TAPE' AS ItemName, N'K15-A' AS LocationCode
    UNION ALL
    SELECT N'00000000406' AS ItemCode, N'0.13X10X20 TAPE BLUE' AS PartsCode, N'PVC TAPE' AS ItemName, N'K15-A' AS LocationCode
    UNION ALL
    SELECT N'00000000407' AS ItemCode, N'0.13X10X20 TAPE WHITE' AS PartsCode, N'PVC TAPE' AS ItemName, N'K15-A' AS LocationCode
    UNION ALL
    SELECT N'00000000402' AS ItemCode, N'0.13X10X20 W-slit' AS PartsCode, N'PVC TAPE' AS ItemName, N'K16-A' AS LocationCode
    UNION ALL
    SELECT N'00000000403' AS ItemCode, N'0.13X19X20 Br' AS PartsCode, N'PVC TAPE' AS ItemName, N'K15-A' AS LocationCode
    UNION ALL
    SELECT N'00000000400' AS ItemCode, N'0.13X19X20 G' AS PartsCode, N'PVC TAPE' AS ItemName, N'K15-A' AS LocationCode
    UNION ALL
    SELECT N'00000000392' AS ItemCode, N'0.13X19X20 GR' AS PartsCode, N'PVC TAPE' AS ItemName, N'K15-A' AS LocationCode
    UNION ALL
    SELECT N'00000000398' AS ItemCode, N'0.13X19X20 P' AS PartsCode, N'PVC TAPE' AS ItemName, N'K15-B' AS LocationCode
    UNION ALL
    SELECT N'00000000397' AS ItemCode, N'0.13X19X20 R' AS PartsCode, N'PVC TAPE' AS ItemName, N'K15-B' AS LocationCode
    UNION ALL
    SELECT N'00000000405' AS ItemCode, N'0.13X19X20 SkyBlue' AS PartsCode, N'PVC TAPE' AS ItemName, N'K15-B' AS LocationCode
    UNION ALL
    SELECT N'00000000401' AS ItemCode, N'0.13X19X20 W' AS PartsCode, N'PVC TAPE' AS ItemName, N'K15-B' AS LocationCode
    UNION ALL
    SELECT N'00000000399' AS ItemCode, N'0.13X19X20 Y' AS PartsCode, N'PVC TAPE' AS ItemName, N'K15-B' AS LocationCode
    UNION ALL
    SELECT N'00000000231' AS ItemCode, N'1123343-1' AS PartsCode, N'TERMINAL' AS ItemName, N'I9-1' AS LocationCode
    UNION ALL
    SELECT N'00000000115' AS ItemCode, N'1318386-2' AS PartsCode, N'CONNECTOR' AS ItemName, N'A4-1' AS LocationCode
    UNION ALL
    SELECT N'00000000232' AS ItemCode, N'1376109-1' AS PartsCode, N'TERMINAL' AS ItemName, N'C3-A' AS LocationCode
    UNION ALL
    SELECT N'00000000239' AS ItemCode, N'1674311-7' AS PartsCode, N'TERMINAL' AS ItemName, N'I1 to I2' AS LocationCode
    UNION ALL
    SELECT N'00000000075' AS ItemCode, N'1746872-1' AS PartsCode, N'CONNECTOR' AS ItemName, N'A2-1 to 2' AS LocationCode
    UNION ALL
    SELECT N'00000000076' AS ItemCode, N'1827842-1' AS PartsCode, N'CONNECTOR' AS ItemName, N'G7' AS LocationCode
    UNION ALL
    SELECT N'00000000209' AS ItemCode, N'1827855-4' AS PartsCode, N'TERMINAL' AS ItemName, N'C4-A to B' AS LocationCode
    UNION ALL
    SELECT N'00000000034' AS ItemCode, N'189114-0040' AS PartsCode, N'CLAMP' AS ItemName, N'2FL-' AS LocationCode
    UNION ALL
    SELECT N'00000000270' AS ItemCode, N'2420F 5-115' AS PartsCode, N'TWIST TUBE' AS ItemName, N'B3-2' AS LocationCode
    UNION ALL
    SELECT N'00000000271' AS ItemCode, N'2420F 5-65' AS PartsCode, N'TWIST TUBE' AS ItemName, N'A5-1' AS LocationCode
    UNION ALL
    SELECT N'00000000211' AS ItemCode, N'316833-2' AS PartsCode, N'TERMINAL' AS ItemName, N'I10-2' AS LocationCode
    UNION ALL
    SELECT N'00000000212' AS ItemCode, N'316836-1' AS PartsCode, N'TERMINAL' AS ItemName, N'I7-1' AS LocationCode
    UNION ALL
    SELECT N'00000000213' AS ItemCode, N'353537-1' AS PartsCode, N'TERMINAL' AS ItemName, N'I8-1' AS LocationCode
    UNION ALL
    SELECT N'00000000123' AS ItemCode, N'4A1230-0000' AS PartsCode, N'CONNECTOR' AS ItemName, N'P23' AS LocationCode
    UNION ALL
    SELECT N'00000000125' AS ItemCode, N'4B1080-0000' AS PartsCode, N'CONNECTOR' AS ItemName, N'G5' AS LocationCode
    UNION ALL
    SELECT N'00000000415' AS ItemCode, N'4F1640-0000' AS PartsCode, N'TRP CONNECTOR' AS ItemName, N'P15' AS LocationCode
    UNION ALL
    SELECT N'00000000416' AS ItemCode, N'4F1640-0000-P' AS PartsCode, N'TRP CONNECTOR' AS ItemName, N'G5' AS LocationCode
    UNION ALL
    SELECT N'00000000126' AS ItemCode, N'4F5260-0000' AS PartsCode, N'CONNECTOR' AS ItemName, N'G4' AS LocationCode
    UNION ALL
    SELECT N'00000000113' AS ItemCode, N'4G5400-0000' AS PartsCode, N'CONNECTOR' AS ItemName, N'B2' AS LocationCode
    UNION ALL
    SELECT N'00000000078' AS ItemCode, N'505570-0600' AS PartsCode, N'CONNECTOR' AS ItemName, N'B3-4' AS LocationCode
    UNION ALL
    SELECT N'00000000214' AS ItemCode, N'505572-1000' AS PartsCode, N'TERMINAL' AS ItemName, N'C3-A' AS LocationCode
    UNION ALL
    SELECT N'00000000079' AS ItemCode, N'6098-2220' AS PartsCode, N'CONNECTOR' AS ItemName, N'A1-1 TO A1-2' AS LocationCode
    UNION ALL
    SELECT N'00000000080' AS ItemCode, N'6098-3802' AS PartsCode, N'CONNECTOR' AS ItemName, N'G9' AS LocationCode
    UNION ALL
    SELECT N'00000000112' AS ItemCode, N'6098-3803' AS PartsCode, N'CONNECTOR' AS ItemName, N'B6-1' AS LocationCode
    UNION ALL
    SELECT N'00000000135' AS ItemCode, N'6098-3805' AS PartsCode, N'CONNECTOR' AS ItemName, N'A5-1' AS LocationCode
    UNION ALL
    SELECT N'00000000081' AS ItemCode, N'6098-3810' AS PartsCode, N'CONNECTOR' AS ItemName, N'B1' AS LocationCode
    UNION ALL
    SELECT N'00000000136' AS ItemCode, N'6098-3811' AS PartsCode, N'CONNECTOR' AS ItemName, N'a3-1' AS LocationCode
    UNION ALL
    SELECT N'00000000082' AS ItemCode, N'6098-5668' AS PartsCode, N'CONNECTOR' AS ItemName, N'B6-2' AS LocationCode
    UNION ALL
    SELECT N'00000000083' AS ItemCode, N'6098-5673' AS PartsCode, N'CONNECTOR' AS ItemName, N'B2' AS LocationCode
    UNION ALL
    SELECT N'00000000084' AS ItemCode, N'6098-5677' AS PartsCode, N'CONNECTOR' AS ItemName, N'B4-3' AS LocationCode
    UNION ALL
    SELECT N'00000000111' AS ItemCode, N'6098-6653' AS PartsCode, N'CONNECTOR' AS ItemName, N'A3-3' AS LocationCode
    UNION ALL
    SELECT N'00000000131' AS ItemCode, N'6098-6662' AS PartsCode, N'CONNECTOR' AS ItemName, N'A2-3' AS LocationCode
    UNION ALL
    SELECT N'00000000085' AS ItemCode, N'6098-6663' AS PartsCode, N'CONNECTOR' AS ItemName, N'B2' AS LocationCode
    UNION ALL
    SELECT N'00000000086' AS ItemCode, N'6188-0066' AS PartsCode, N'CONNECTOR' AS ItemName, N'G1' AS LocationCode
    UNION ALL
    SELECT N'00000000088' AS ItemCode, N'6188-0093' AS PartsCode, N'CONNECTOR' AS ItemName, N'B4-3' AS LocationCode
    UNION ALL
    SELECT N'00000000151' AS ItemCode, N'6188-0175' AS PartsCode, N'CONNECTOR' AS ItemName, N'B3-1' AS LocationCode
    UNION ALL
    SELECT N'00000000089' AS ItemCode, N'6188-0294' AS PartsCode, N'CONNECTOR' AS ItemName, N'2FL NM' AS LocationCode
    UNION ALL
    SELECT N'00000000090' AS ItemCode, N'6188-0407' AS PartsCode, N'CONNECTOR' AS ItemName, N'A6-3to A6-4' AS LocationCode
    UNION ALL
    SELECT N'00000000092' AS ItemCode, N'6188-0779' AS PartsCode, N'CONNECTOR' AS ItemName, N'B4-1' AS LocationCode
    UNION ALL
    SELECT N'00000000095' AS ItemCode, N'6189-0451' AS PartsCode, N'CONNECTOR' AS ItemName, N'B1' AS LocationCode
    UNION ALL
    SELECT N'00000000098' AS ItemCode, N'6189-1142' AS PartsCode, N'CONNECTOR' AS ItemName, N'A6-1 TO 2' AS LocationCode
    UNION ALL
    SELECT N'00000000099' AS ItemCode, N'6189-1161' AS PartsCode, N'CONNECTOR' AS ItemName, N'B5-1 to 3' AS LocationCode
    UNION ALL
    SELECT N'00000000193' AS ItemCode, N'61A180-0051' AS PartsCode, N'PUSH NUT' AS ItemName, N'2FL-B' AS LocationCode
    UNION ALL
    SELECT N'00000000139' AS ItemCode, N'6249-1252' AS PartsCode, N'CONNECTOR' AS ItemName, N'B4-1' AS LocationCode
    UNION ALL
    SELECT N'00000000127' AS ItemCode, N'6520-0550' AS PartsCode, N'CONNECTOR' AS ItemName, N'2FL NM' AS LocationCode
    UNION ALL
    SELECT N'00000000238' AS ItemCode, N'7114-1170 (4R2091-0000)' AS PartsCode, N'TERMINAL' AS ItemName, N'2FL' AS LocationCode
    UNION ALL
    SELECT N'00000000215' AS ItemCode, N'7114-4020' AS PartsCode, N'TERMINAL' AS ItemName, N'I7-2' AS LocationCode
    UNION ALL
    SELECT N'00000000217' AS ItemCode, N'7114-4025' AS PartsCode, N'TERMINAL' AS ItemName, N'D6-B' AS LocationCode
    UNION ALL
    SELECT N'00000000236' AS ItemCode, N'7114-410002' AS PartsCode, N'TERMINAL' AS ItemName, N'I10-2' AS LocationCode
    UNION ALL
    SELECT N'00000000233' AS ItemCode, N'7114-738602' AS PartsCode, N'TERMINAL' AS ItemName, N'I8-1' AS LocationCode
    UNION ALL
    SELECT N'00000000237' AS ItemCode, N'7116-1180 (4R1121-0000)' AS PartsCode, N'TERMINAL' AS ItemName, N'2FL' AS LocationCode
    UNION ALL
    SELECT N'00000000218' AS ItemCode, N'7116-4020' AS PartsCode, N'TERMINAL' AS ItemName, N'I3-1' AS LocationCode
    UNION ALL
    SELECT N'00000000219' AS ItemCode, N'7116-4025' AS PartsCode, N'TERMINAL' AS ItemName, N'I6-1' AS LocationCode
    UNION ALL
    SELECT N'00000000144' AS ItemCode, N'7123-1520' AS PartsCode, N'CONNECTOR' AS ItemName, N'2FL' AS LocationCode
    UNION ALL
    SELECT N'00000000285' AS ItemCode, N'7158-307080' AS PartsCode, N'WIRE SEAL' AS ItemName, N'A1-4' AS LocationCode
    UNION ALL
    SELECT N'00000000286' AS ItemCode, N'7160-9465' AS PartsCode, N'DUMMY SEAL' AS ItemName, N'B4-2' AS LocationCode
    UNION ALL
    SELECT N'00000000287' AS ItemCode, N'7165-0349' AS PartsCode, N'WIRE SEAL' AS ItemName, N'A1-4' AS LocationCode
    UNION ALL
    SELECT N'00000000288' AS ItemCode, N'7165-0796' AS PartsCode, N'WIRE SEAL' AS ItemName, N'B4-4' AS LocationCode
    UNION ALL
    SELECT N'00000000289' AS ItemCode, N'7165-0797' AS PartsCode, N'DUMMY SEAL' AS ItemName, N'B3-1' AS LocationCode
    UNION ALL
    SELECT N'00000000140' AS ItemCode, N'7182-8049' AS PartsCode, N'CONNECTOR' AS ItemName, N'B-4-4' AS LocationCode
    UNION ALL
    SELECT N'00000000142' AS ItemCode, N'7186-8845' AS PartsCode, N'CONNECTOR' AS ItemName, N'2FL NM' AS LocationCode
    UNION ALL
    SELECT N'00000000128' AS ItemCode, N'7186-8847' AS PartsCode, N'CONNECTOR' AS ItemName, N'A3-2' AS LocationCode
    UNION ALL
    SELECT N'00000000145' AS ItemCode, N'7186-8849' AS PartsCode, N'CONNECTOR' AS ItemName, N'2FL' AS LocationCode
    UNION ALL
    SELECT N'00000000141' AS ItemCode, N'7188-337510' AS PartsCode, N'CONNECTOR' AS ItemName, N'P26' AS LocationCode
    UNION ALL
    SELECT N'00000000101' AS ItemCode, N'7282-1020' AS PartsCode, N'CONNECTOR' AS ItemName, N'B3-1' AS LocationCode
    UNION ALL
    SELECT N'00000000102' AS ItemCode, N'7282-1026' AS PartsCode, N'CONNECTOR' AS ItemName, N'2FL' AS LocationCode
    UNION ALL
    SELECT N'00000000117' AS ItemCode, N'7282-1027' AS PartsCode, N'CONNECTOR' AS ItemName, N'A2-4' AS LocationCode
    UNION ALL
    SELECT N'00000000129' AS ItemCode, N'7282-1028' AS PartsCode, N'CONNECTOR' AS ItemName, N'A3-1' AS LocationCode
    UNION ALL
    SELECT N'00000000148' AS ItemCode, N'7282-1060' AS PartsCode, N'CONNECTOR' AS ItemName, N'B6-3' AS LocationCode
    UNION ALL
    SELECT N'00000000124' AS ItemCode, N'7282-5976' AS PartsCode, N'CONNECTOR' AS ItemName, N'A4-4' AS LocationCode
    UNION ALL
    SELECT N'00000000105' AS ItemCode, N'7282-597840' AS PartsCode, N'CONNECTOR' AS ItemName, N'A5-2' AS LocationCode
    UNION ALL
    SELECT N'00000000146' AS ItemCode, N'7282-8324' AS PartsCode, N'CONNECTOR' AS ItemName, N'2FL' AS LocationCode
    UNION ALL
    SELECT N'00000000130' AS ItemCode, N'7283-1020' AS PartsCode, N'CONNECTOR' AS ItemName, N'A5-1' AS LocationCode
    UNION ALL
    SELECT N'00000000106' AS ItemCode, N'7283-102060' AS PartsCode, N'CONNECTOR' AS ItemName, N'a3-1' AS LocationCode
    UNION ALL
    SELECT N'00000000143' AS ItemCode, N'7283-1028' AS PartsCode, N'CONNECTOR' AS ItemName, N'2FL' AS LocationCode
    UNION ALL
    SELECT N'00000000138' AS ItemCode, N'7283-1030' AS PartsCode, N'CONNECTOR' AS ItemName, N'B4-1' AS LocationCode
    UNION ALL
    SELECT N'00000000147' AS ItemCode, N'7283-1040 (4F5450-0000)' AS PartsCode, N'CONNECTOR' AS ItemName, N'2FL' AS LocationCode
    UNION ALL
    SELECT N'00000000137' AS ItemCode, N'7283-1138' AS PartsCode, N'CONNECTOR' AS ItemName, N'B4-4' AS LocationCode
    UNION ALL
    SELECT N'00000000035' AS ItemCode, N'730412-6280 (82711-3A640)' AS PartsCode, N'CLAMP' AS ItemName, N'2FL-D' AS LocationCode
    UNION ALL
    SELECT N'00000000036' AS ItemCode, N'730415-3300 (82711-35730)' AS PartsCode, N'CLAMP' AS ItemName, N'2FL-C' AS LocationCode
    UNION ALL
    SELECT N'00000000038' AS ItemCode, N'730415-6440 (82712-28660)' AS PartsCode, N'CLAMP' AS ItemName, N'2FL' AS LocationCode
    UNION ALL
    SELECT N'00000000039' AS ItemCode, N'730418-4690' AS PartsCode, N'CLAMP' AS ItemName, N'2FL' AS LocationCode
    UNION ALL
    SELECT N'00000000192' AS ItemCode, N'730481-5030' AS PartsCode, N'PROTECTER' AS ItemName, N'2FL-B' AS LocationCode
    UNION ALL
    SELECT N'00000000108' AS ItemCode, N'7C83-552470' AS PartsCode, N'CONNECTOR' AS ItemName, N'a3-4' AS LocationCode
    UNION ALL
    SELECT N'00000000161' AS ItemCode, N'7D0349-0040' AS PartsCode, N'CONTACT' AS ItemName, N'2FL-C' AS LocationCode
    UNION ALL
    SELECT N'00000000228' AS ItemCode, N'7D0349-0060' AS PartsCode, N'TERMINAL' AS ItemName, N'D5-A to B' AS LocationCode
    UNION ALL
    SELECT N'00000000197' AS ItemCode, N'7K0575-0010' AS PartsCode, N'SLIDER' AS ItemName, N'2FL-B' AS LocationCode
    UNION ALL
    SELECT N'00000000204' AS ItemCode, N'7K0575-0022H' AS PartsCode, N'STATOR' AS ItemName, N'2FL-A' AS LocationCode
    UNION ALL
    SELECT N'00000000171' AS ItemCode, N'7K0575-0031' AS PartsCode, N'HOLDER' AS ItemName, N'2FL-A' AS LocationCode
    UNION ALL
    SELECT N'00000000198' AS ItemCode, N'7K0580-0010' AS PartsCode, N'SLIDER' AS ItemName, N'2FL-B' AS LocationCode
    UNION ALL
    SELECT N'00000000173' AS ItemCode, N'7K0580-0020' AS PartsCode, N'HOUSING' AS ItemName, N'2FL' AS LocationCode
    UNION ALL
    SELECT N'00000000191' AS ItemCode, N'7K0580-0030' AS PartsCode, N'PLATE' AS ItemName, N'2FL-B' AS LocationCode
    UNION ALL
    SELECT N'00000000202' AS ItemCode, N'7K0580-0040' AS PartsCode, N'SPRING' AS ItemName, N'2FL-B' AS LocationCode
    UNION ALL
    SELECT N'00000000307' AS ItemCode, N'7K0580-0050' AS PartsCode, N'MAGNET' AS ItemName, N'P' AS LocationCode
    UNION ALL
    SELECT N'00000000206' AS ItemCode, N'7K0639-0021H' AS PartsCode, N'STATOR' AS ItemName, N'2FL-C' AS LocationCode
    UNION ALL
    SELECT N'00000000172' AS ItemCode, N'7K0639-0030' AS PartsCode, N'HOLDER' AS ItemName, N'2FL' AS LocationCode
    UNION ALL
    SELECT N'00000012176' AS ItemCode, N'7K0639-0030F' AS PartsCode, N'HOLDER' AS ItemName, N'2FL-A' AS LocationCode
    UNION ALL
    SELECT N'00000000205' AS ItemCode, N'7M0011-0020H' AS PartsCode, N'STATOR' AS ItemName, N'2FL-A' AS LocationCode
    UNION ALL
    SELECT N'00000000308' AS ItemCode, N'7M0531-0021' AS PartsCode, N'URETHANE FOAM' AS ItemName, N'G5' AS LocationCode
    UNION ALL
    SELECT N'00000000185' AS ItemCode, N'7N0077-7060P' AS PartsCode, N'PCB' AS ItemName, N'2FL-C' AS LocationCode
    UNION ALL
    SELECT N'00000000186' AS ItemCode, N'7N0104-7060P' AS PartsCode, N'PCB' AS ItemName, N'2FL-A' AS LocationCode
    UNION ALL
    SELECT N'00000000351' AS ItemCode, N'7V1070-0020 (73230-06750) 30' AS PartsCode, N'QR CODE LABEL' AS ItemName, N'K16-A' AS LocationCode
    UNION ALL
    SELECT N'00000000352' AS ItemCode, N'7V1080-0020 (73230-06B20) 32' AS PartsCode, N'QR CODE LABEL' AS ItemName, N'K16-A' AS LocationCode
    UNION ALL
    SELECT N'00000000353' AS ItemCode, N'7V2070-0020 (73230-06740) 31' AS PartsCode, N'QR CODE LABEL' AS ItemName, N'2FL NM' AS LocationCode
    UNION ALL
    SELECT N'00000000354' AS ItemCode, N'7V2080-0020 (73230-06760) 33' AS PartsCode, N'QR CODE LABEL' AS ItemName, N'2FL NM' AS LocationCode
    UNION ALL
    SELECT N'00000000350' AS ItemCode, N'7V2200-002(73230-07060) 47' AS PartsCode, N'QR CODE LABEL' AS ItemName, N'2FL NM' AS LocationCode
    UNION ALL
    SELECT N'00000000220' AS ItemCode, N'8100-3455' AS PartsCode, N'TERMINAL' AS ItemName, N'I6-2' AS LocationCode
    UNION ALL
    SELECT N'00000000221' AS ItemCode, N'8100-3617' AS PartsCode, N'TERMINAL' AS ItemName, N'C3-B' AS LocationCode
    UNION ALL
    SELECT N'00000000222' AS ItemCode, N'8100-3623' AS PartsCode, N'TERMINAL' AS ItemName, N'I4' AS LocationCode
    UNION ALL
    SELECT N'00000000223' AS ItemCode, N'8100-3625' AS PartsCode, N'TERMINAL' AS ItemName, N'I5' AS LocationCode
    UNION ALL
    SELECT N'00000000224' AS ItemCode, N'8230-4925' AS PartsCode, N'TERMINAL' AS ItemName, N'I3-2' AS LocationCode
    UNION ALL
    SELECT N'00000000225' AS ItemCode, N'8240-0182' AS PartsCode, N'TERMINAL' AS ItemName, N'D6-A' AS LocationCode
    UNION ALL
    SELECT N'00000000226' AS ItemCode, N'8240-0215' AS PartsCode, N'TERMINAL' AS ItemName, N'I9-1' AS LocationCode
    UNION ALL
    SELECT N'00000000071' AS ItemCode, N'82711-12A60' AS PartsCode, N'CLAMP' AS ItemName, N'2FL-E' AS LocationCode
    UNION ALL
    SELECT N'00000000072' AS ItemCode, N'82711-12A80' AS PartsCode, N'Clamp' AS ItemName, N'2FL-D' AS LocationCode
    UNION ALL
    SELECT N'00000000041' AS ItemCode, N'82711-16820' AS PartsCode, N'CLAMP' AS ItemName, N'2FL-E' AS LocationCode
    UNION ALL
    SELECT N'00000000042' AS ItemCode, N'82711-16830' AS PartsCode, N'CLAMP' AS ItemName, N'2FL-E' AS LocationCode
    UNION ALL
    SELECT N'00000000067' AS ItemCode, N'82711-1A830' AS PartsCode, N'CLAMP' AS ItemName, N'2FL-D' AS LocationCode
    UNION ALL
    SELECT N'00000000044' AS ItemCode, N'82711-1E360' AS PartsCode, N'CLAMP' AS ItemName, N'2FL-D' AS LocationCode
    UNION ALL
    SELECT N'00000000046' AS ItemCode, N'82711-21020' AS PartsCode, N'CLAMP' AS ItemName, N'2FL NM' AS LocationCode
    UNION ALL
    SELECT N'00000000069' AS ItemCode, N'82711-26380' AS PartsCode, N'CLAMP' AS ItemName, N'2FL-F' AS LocationCode
    UNION ALL
    SELECT N'00000000070' AS ItemCode, N'82711-33380' AS PartsCode, N'CLAMP' AS ItemName, N'2FL-D' AS LocationCode
    UNION ALL
    SELECT N'00000000048' AS ItemCode, N'82711-34490' AS PartsCode, N'CLAMP' AS ItemName, N'2FL-E' AS LocationCode
    UNION ALL
    SELECT N'00000000049' AS ItemCode, N'82711-3A540' AS PartsCode, N'CLAMP' AS ItemName, N'2FL-D' AS LocationCode
    UNION ALL
    SELECT N'00000000050' AS ItemCode, N'82711-3F290 (730415-0100)' AS PartsCode, N'CLAMP' AS ItemName, N'2FL-D' AS LocationCode
    UNION ALL
    SELECT N'00000000051' AS ItemCode, N'82711-3F440' AS PartsCode, N'CLAMP' AS ItemName, N'2FL NM' AS LocationCode
    UNION ALL
    SELECT N'00000000052' AS ItemCode, N'82711-48020 ( 730418-0160 )' AS PartsCode, N'CLAMP' AS ItemName, N'2FL-D' AS LocationCode
    UNION ALL
    SELECT N'00000000053' AS ItemCode, N'82711-48070' AS PartsCode, N'CLAMP' AS ItemName, N'2FL-C' AS LocationCode
    UNION ALL
    SELECT N'00000000054' AS ItemCode, N'82711-48210' AS PartsCode, N'CLAMP' AS ItemName, N'2FL-F' AS LocationCode
    UNION ALL
    SELECT N'00000000055' AS ItemCode, N'82711-48240' AS PartsCode, N'CLAMP' AS ItemName, N'2FL NM' AS LocationCode
    UNION ALL
    SELECT N'00000000063' AS ItemCode, N'82711-52070(129902-0010)' AS PartsCode, N'CLAMP' AS ItemName, N'2FL-F' AS LocationCode
    UNION ALL
    SELECT N'00000000056' AS ItemCode, N'82711-52090' AS PartsCode, N'CLAMP' AS ItemName, N'2FL-G' AS LocationCode
    UNION ALL
    SELECT N'00000000068' AS ItemCode, N'82711-60270' AS PartsCode, N'CLAMP' AS ItemName, N'2FL-D' AS LocationCode
    UNION ALL
    SELECT N'00000000058' AS ItemCode, N'82711-60640' AS PartsCode, N'CLAMP' AS ItemName, N'2FL-D' AS LocationCode
    UNION ALL
    SELECT N'00000000059' AS ItemCode, N'82712-75390' AS PartsCode, N'CLAMP' AS ItemName, N'2FL NM' AS LocationCode
    UNION ALL
    SELECT N'00000000332' AS ItemCode, N'AVSS0.3 B' AS PartsCode, N'IEWP WIRE' AS ItemName, N'M11-5' AS LocationCode
    UNION ALL
    SELECT N'00000000345' AS ItemCode, N'AVSS0.3 B/W' AS PartsCode, N'IEWP WIRE' AS ItemName, N'M14-2' AS LocationCode
    UNION ALL
    SELECT N'00000000312' AS ItemCode, N'AVSS0.3 BR' AS PartsCode, N'IEWP WIRE' AS ItemName, N'M13-3' AS LocationCode
    UNION ALL
    SELECT N'00000000313' AS ItemCode, N'AVSS0.3 G' AS PartsCode, N'IEWP WIRE' AS ItemName, N'M13-1' AS LocationCode
    UNION ALL
    SELECT N'00000000314' AS ItemCode, N'AVSS0.3 GR' AS PartsCode, N'IEWP WIRE' AS ItemName, N'M14-4' AS LocationCode
    UNION ALL
    SELECT N'00000000315' AS ItemCode, N'AVSS0.3 GR/B' AS PartsCode, N'IEWP WIRE' AS ItemName, N'M12-5' AS LocationCode
    UNION ALL
    SELECT N'00000000316' AS ItemCode, N'AVSS0.3 L' AS PartsCode, N'IEWP WIRE' AS ItemName, N'M12-3' AS LocationCode
    UNION ALL
    SELECT N'00000000317' AS ItemCode, N'AVSS0.3 LG' AS PartsCode, N'IEWP WIRE' AS ItemName, N'M13-2' AS LocationCode
    UNION ALL
    SELECT N'00000000338' AS ItemCode, N'AVSS0.3 OR' AS PartsCode, N'IEWP WIRE' AS ItemName, N'M11-3' AS LocationCode
    UNION ALL
    SELECT N'00000000318' AS ItemCode, N'AVSS0.3 P' AS PartsCode, N'IEWP WIRE' AS ItemName, N'M12-2' AS LocationCode
    UNION ALL
    SELECT N'00000000319' AS ItemCode, N'AVSS0.3 R' AS PartsCode, N'IEWP WIRE' AS ItemName, N'M14-3' AS LocationCode
    UNION ALL
    SELECT N'00000000320' AS ItemCode, N'AVSS0.3 R/L' AS PartsCode, N'IEWP WIRE' AS ItemName, N'M12-1' AS LocationCode
    UNION ALL
    SELECT N'00000000321' AS ItemCode, N'AVSS0.3 R/W' AS PartsCode, N'IEWP WIRE' AS ItemName, N'M12-4' AS LocationCode
    UNION ALL
    SELECT N'00000000322' AS ItemCode, N'AVSS0.3 V' AS PartsCode, N'IEWP WIRE' AS ItemName, N'M13-4' AS LocationCode
    UNION ALL
    SELECT N'00000000323' AS ItemCode, N'AVSS0.3 W' AS PartsCode, N'IEWP WIRE' AS ItemName, N'M12-3' AS LocationCode
    UNION ALL
    SELECT N'00000000324' AS ItemCode, N'AVSS0.3 W/G' AS PartsCode, N'IEWP WIRE' AS ItemName, N'M12-5' AS LocationCode
    UNION ALL
    SELECT N'00000000325' AS ItemCode, N'AVSS0.3 Y' AS PartsCode, N'IEWP WIRE' AS ItemName, N'M14-1' AS LocationCode
    UNION ALL
    SELECT N'00000000339' AS ItemCode, N'AVSS0.5 L' AS PartsCode, N'IEWP WIRE' AS ItemName, N'M14-5' AS LocationCode
    UNION ALL
    SELECT N'00000000337' AS ItemCode, N'AVSS0.5 OR' AS PartsCode, N'IEWP WIRE' AS ItemName, N'M-PLT' AS LocationCode
    UNION ALL
    SELECT N'00000000333' AS ItemCode, N'AVSSF 0.3F B' AS PartsCode, N'IEWP WIRE' AS ItemName, N'M-PLT' AS LocationCode
    UNION ALL
    SELECT N'00000000334' AS ItemCode, N'AVSSF 0.3F B/W' AS PartsCode, N'IEWP WIRE' AS ItemName, N'M4-3' AS LocationCode
    UNION ALL
    SELECT N'00000000327' AS ItemCode, N'AVSSF 0.3F Br' AS PartsCode, N'IEWP WIRE' AS ItemName, N'M7-2' AS LocationCode
    UNION ALL
    SELECT N'00000000329' AS ItemCode, N'AVSSF 0.3F G' AS PartsCode, N'IEWP WIRE' AS ItemName, N'M7-4' AS LocationCode
    UNION ALL
    SELECT N'00000000335' AS ItemCode, N'AVSSF 0.3F Gr' AS PartsCode, N'IEWP WIRE' AS ItemName, N'M4-1 to 2' AS LocationCode
    UNION ALL
    SELECT N'00000000330' AS ItemCode, N'AVSSF 0.3F L' AS PartsCode, N'IEWP WIRE' AS ItemName, N'M3-4' AS LocationCode
    UNION ALL
    SELECT N'00000000340' AS ItemCode, N'AVSSF 0.3f LG' AS PartsCode, N'IEWP WIRE' AS ItemName, N'2FL' AS LocationCode
    UNION ALL
    SELECT N'00000000336' AS ItemCode, N'AVSSF 0.3F Or' AS PartsCode, N'IEWP WIRE' AS ItemName, N'M6-1 to 4' AS LocationCode
    UNION ALL
    SELECT N'00000000328' AS ItemCode, N'AVSSF 0.3F P' AS PartsCode, N'IEWP WIRE' AS ItemName, N'M7-3' AS LocationCode
    UNION ALL
    SELECT N'00000000341' AS ItemCode, N'AVSSF 0.3f R' AS PartsCode, N'IEWP WIRE' AS ItemName, N'M4-4' AS LocationCode
    UNION ALL
    SELECT N'00000000342' AS ItemCode, N'AVSSF 0.3f R/W' AS PartsCode, N'IEWP WIRE' AS ItemName, N'M5-4' AS LocationCode
    UNION ALL
    SELECT N'00000000331' AS ItemCode, N'AVSSF 0.3F V' AS PartsCode, N'IEWP WIRE' AS ItemName, N'M7-1' AS LocationCode
    UNION ALL
    SELECT N'00000000343' AS ItemCode, N'AVSSF 0.3f W' AS PartsCode, N'IEWP WIRE' AS ItemName, N'M5-4' AS LocationCode
    UNION ALL
    SELECT N'00000000346' AS ItemCode, N'AVSSF 0.3f W/B' AS PartsCode, N'IEWP WIRE' AS ItemName, N'M3-5' AS LocationCode
    UNION ALL
    SELECT N'00000000344' AS ItemCode, N'AVSSF 0.3f W/L' AS PartsCode, N'IEWP WIRE' AS ItemName, N'2FL' AS LocationCode
    UNION ALL
    SELECT N'00000000326' AS ItemCode, N'AVSSF 0.3F Y' AS PartsCode, N'IEWP WIRE' AS ItemName, N'M5-1 to 3' AS LocationCode
    UNION ALL
    SELECT N'00000011559' AS ItemCode, N'AVSS 0.3 G/W' AS PartsCode, N'IEWP WIRE' AS ItemName, N'M2-3' AS LocationCode
    UNION ALL
    SELECT N'00000000062' AS ItemCode, N'B001200839' AS PartsCode, N'CLAMP' AS ItemName, N'B3-5' AS LocationCode
    UNION ALL
    SELECT N'00000000027' AS ItemCode, N'CIVUS 0.13 Black' AS PartsCode, N'CIVUS WIRE' AS ItemName, N'K8' AS LocationCode
    UNION ALL
    SELECT N'00000000016' AS ItemCode, N'CIVUS 0.13 C (BROWN)' AS PartsCode, N'CIVUS WIRE' AS ItemName, N'K2' AS LocationCode
    UNION ALL
    SELECT N'00000000025' AS ItemCode, N'CIVUS 0.13 G' AS PartsCode, N'CIVUS WIRE' AS ItemName, N'K9' AS LocationCode
    UNION ALL
    SELECT N'00000000021' AS ItemCode, N'CIVUS 0.13 H (GRAY)' AS PartsCode, N'CIVUS WIRE' AS ItemName, N'K5' AS LocationCode
    UNION ALL
    SELECT N'00000000017' AS ItemCode, N'CIVUS 0.13 L' AS PartsCode, N'CIVUS WIRE' AS ItemName, N'K6' AS LocationCode
    UNION ALL
    SELECT N'00000000018' AS ItemCode, N'CIVUS 0.13 Light Green(K)' AS PartsCode, N'CIVUS WIRE' AS ItemName, N'K4' AS LocationCode
    UNION ALL
    SELECT N'00000000024' AS ItemCode, N'CIVUS 0.13 O' AS PartsCode, N'CIVUS WIRE' AS ItemName, N'K11' AS LocationCode
    UNION ALL
    SELECT N'00000000019' AS ItemCode, N'CIVUS 0.13 P' AS PartsCode, N'CIVUS WIRE' AS ItemName, N'K10' AS LocationCode
    UNION ALL
    SELECT N'00000000026' AS ItemCode, N'CIVUS 0.13 Red' AS PartsCode, N'CIVUS WIRE' AS ItemName, N'K7' AS LocationCode
    UNION ALL
    SELECT N'00000000020' AS ItemCode, N'CIVUS 0.13 Violet(M)' AS PartsCode, N'CIVUS WIRE' AS ItemName, N'K3' AS LocationCode
    UNION ALL
    SELECT N'00000000023' AS ItemCode, N'CIVUS 0.13 W' AS PartsCode, N'CIVUS WIRE' AS ItemName, N'K12' AS LocationCode
    UNION ALL
    SELECT N'00000000022' AS ItemCode, N'CIVUS 0.13 Y' AS PartsCode, N'CIVUS WIRE' AS ItemName, N'K1' AS LocationCode
    UNION ALL
    SELECT N'0000000002' AS ItemCode, N'CV PP3 BKNO 05 BABY(MR W1 AG)      DELFINGEN' AS PartsCode, N'CORRUGATED TUBE' AS ItemName, N'H5' AS LocationCode
    UNION ALL
    SELECT N'0000000003' AS ItemCode, N'CV PP3 BKNO 07 BABY MR AR' AS PartsCode, N'CORRUGATED TUBE' AS ItemName, N'2FL' AS LocationCode
    UNION ALL
    SELECT N'0000000001' AS ItemCode, N'CVNS PP3 BKNO 05 BABY(MR W1 AG) DELFINGEN' AS PartsCode, N'CORRUGATED TUBE' AS ItemName, N'L1' AS LocationCode
    UNION ALL
    SELECT N'0000000004' AS ItemCode, N'CVNS PP3 BKNO 07BABY(MR AR)           DELFINGEN' AS PartsCode, N'CORRUGATED TUBE' AS ItemName, N'L2' AS LocationCode
    UNION ALL
    SELECT N'0000000005' AS ItemCode, N'CVNS PP3 BKNO 10 BABY MR BM' AS PartsCode, N'CORRUGATED TUBE' AS ItemName, N'H3' AS LocationCode
    UNION ALL
    SELECT N'00000000408' AS ItemCode, N'EPT SEALER (PAPTI)' AS PartsCode, N'EPT SEALER' AS ItemName, N'D1' AS LocationCode
    UNION ALL
    SELECT N'00000000411' AS ItemCode, N'EPT SEALER NO. 686 Size: 3mm x 52mm x 181mm; Color: Black' AS PartsCode, N'EPT SEALER' AS ItemName, N'2FL' AS LocationCode
    UNION ALL
    SELECT N'00000000409' AS ItemCode, N'EPT SEALER T3MM,52X171MM' AS PartsCode, N'EPT SEALER' AS ItemName, N'G2' AS LocationCode
    UNION ALL
    SELECT N'00000000414' AS ItemCode, N'IRRAX A 0.3 (7/7/0.1) PURPLE' AS PartsCode, N'IRRAX WIRE' AS ItemName, N'K13' AS LocationCode
    UNION ALL
    SELECT N'00000000412' AS ItemCode, N'IRRAX A 0.3(7/7/0.1)B TAISEI' AS PartsCode, N'IRRAX WIRE' AS ItemName, N'K14' AS LocationCode
    UNION ALL
    SELECT N'00000000114' AS ItemCode, N'PBVP-04V-S' AS PartsCode, N'CONNECTOR' AS ItemName, N'B5-4' AS LocationCode
    UNION ALL
    SELECT N'00000000116' AS ItemCode, N'PBVP-06V-S' AS PartsCode, N'CONNECTOR' AS ItemName, N'A3-2' AS LocationCode
    UNION ALL
    SELECT N'00000000109' AS ItemCode, N'PBVP-08V-S' AS PartsCode, N'CONNECTOR' AS ItemName, N'B3-3' AS LocationCode
    UNION ALL
    SELECT N'00000000110' AS ItemCode, N'PBVP-10V-S' AS PartsCode, N'CONNECTOR' AS ItemName, N'B1' AS LocationCode
    UNION ALL
    SELECT N'00000000132' AS ItemCode, N'PBVP-12V-S' AS PartsCode, N'CONNECTOR' AS ItemName, N'B4-2' AS LocationCode
    UNION ALL
    SELECT N'00000000311' AS ItemCode, N'POLYURETHANE FOAM T4MMX30MMX 75MM' AS PartsCode, N'URETHANE FOAM' AS ItemName, N'D3' AS LocationCode
    UNION ALL
    SELECT N'00000000064' AS ItemCode, N'POP-7067-0' AS PartsCode, N'CLAMP' AS ItemName, N'B3-3' AS LocationCode
    UNION ALL
    SELECT N'00000000393' AS ItemCode, N'PVC TAPE 2107-TVH 0.13 x 19mm x 20M   NITTO ORANGE' AS PartsCode, N'PVC TAPE' AS ItemName, N'K15-B' AS LocationCode
    UNION ALL
    SELECT N'00000000394' AS ItemCode, N'PVC TAPE 2107-TVH 0.13 x 19mm x 20M  NITTO BLUE' AS PartsCode, N'PVC TAPE' AS ItemName, N'K15-B' AS LocationCode
    UNION ALL
    SELECT N'00000000395' AS ItemCode, N'PVC TAPE 2107-TVH 0.13 x 19mm x 20M  NITTO LIGHT GREEN' AS PartsCode, N'PVC TAPE' AS ItemName, N'K16-A' AS LocationCode
    UNION ALL
    SELECT N'00000000396' AS ItemCode, N'PVC TAPE 2107-TVH 0.13 x 19mm x 20M  NITTO VIOLET' AS PartsCode, N'PVC TAPE' AS ItemName, N'K16-A' AS LocationCode
    UNION ALL
    SELECT N'00000000013' AS ItemCode, N'PVC90 04,0X05,0 Bulk      DELFINGEN' AS PartsCode, N'PVC TUBE' AS ItemName, N'F4' AS LocationCode
    UNION ALL
    SELECT N'0000000007' AS ItemCode, N'PVC90 05,0X06,0 Bulk      DELFINGEN' AS PartsCode, N'PVC TUBE' AS ItemName, N'F1' AS LocationCode
    UNION ALL
    SELECT N'00000000014' AS ItemCode, N'PVC90 06,0X07,0 Bulk      DELFINGEN' AS PartsCode, N'PVC TUBE' AS ItemName, N'F3' AS LocationCode
    UNION ALL
    SELECT N'00000000011' AS ItemCode, N'PVC90 07,0X08,0 Bulk      DELFINGEN' AS PartsCode, N'PVC TUBE' AS ItemName, N'F2' AS LocationCode
    UNION ALL
    SELECT N'0000000008' AS ItemCode, N'PVC90 10,0X11,0 Bulk      DELFINGEN' AS PartsCode, N'PVC TUBE' AS ItemName, N'2FL NM' AS LocationCode
    UNION ALL
    SELECT N'00000000227' AS ItemCode, N'SPHD-001T-P0.5' AS PartsCode, N'TERMINAL' AS ItemName, N'I8-2' AS LocationCode
    UNION ALL
    SELECT N'00000000230' AS ItemCode, N'SPS-21T-205 ï¾†ï¾ï½±ï¾‚' AS PartsCode, N'TERMINAL' AS ItemName, N'C2-A' AS LocationCode
    UNION ALL
    SELECT N'00000000234' AS ItemCode, N'SXA-001T-P0.6' AS PartsCode, N'TERMINAL' AS ItemName, N'D6' AS LocationCode
    UNION ALL
    SELECT N'00000000065' AS ItemCode, N'T50RFT8-HSB' AS PartsCode, N'CLAMP' AS ItemName, N'2FL-D' AS LocationCode
    UNION ALL
    SELECT N'00000000066' AS ItemCode, N'T50ROSEC5A' AS PartsCode, N'CLAMP' AS ItemName, N'2FL-D' AS LocationCode
    UNION ALL
    SELECT N'00000002220' AS ItemCode, N'THERMELT865' AS PartsCode, N'THERMELT' AS ItemName, N'S' AS LocationCode
    UNION ALL
    SELECT N'00000000283' AS ItemCode, N'TVSSC 0.3F B/W' AS PartsCode, N'WIRE' AS ItemName, N'M-PLT' AS LocationCode
    UNION ALL
    SELECT N'00000000284' AS ItemCode, N'TVSSC 0.3F G' AS PartsCode, N'Wire' AS ItemName, N'M-PLT' AS LocationCode
    UNION ALL
    SELECT N'00000000282' AS ItemCode, N'TVSSC 0.3F GR' AS PartsCode, N'WIRE' AS ItemName, N'M-PLT' AS LocationCode
    UNION ALL
    SELECT N'00000000259' AS ItemCode, N'VM 10X11' AS PartsCode, N'SUNPRENE TUBE' AS ItemName, N'N3-1' AS LocationCode
    UNION ALL
    SELECT N'00000000260' AS ItemCode, N'VM 11X12' AS PartsCode, N'SUNPRENE TUBE' AS ItemName, N'2FL' AS LocationCode
    UNION ALL
    SELECT N'00000000246' AS ItemCode, N'VM 3x4B' AS PartsCode, N'SUNPRENE TUBE' AS ItemName, N'N5-2' AS LocationCode
    UNION ALL
    SELECT N'00000000261' AS ItemCode, N'VM 4.5 X 5.5 G' AS PartsCode, N'SUNPRENE TUBE' AS ItemName, N'N5-3' AS LocationCode
    UNION ALL
    SELECT N'00000000262' AS ItemCode, N'VM 5.5 X 6.5' AS PartsCode, N'SUNPRENE TUBE' AS ItemName, N'N2-1' AS LocationCode
    UNION ALL
    SELECT N'00000000279' AS ItemCode, N'VM 5.5x6.5 DBR' AS PartsCode, N'SUNPRENE TUBE' AS ItemName, N'2FL' AS LocationCode
    UNION ALL
    SELECT N'00000000263' AS ItemCode, N'VM 5X6 DBR' AS PartsCode, N'SUNPRENE TUBE' AS ItemName, N'N1-5' AS LocationCode
    UNION ALL
    SELECT N'00000000269' AS ItemCode, N'VM 5X6 G' AS PartsCode, N'SUNPRENE TUBE' AS ItemName, N'N1-5' AS LocationCode
    UNION ALL
    SELECT N'00000000264' AS ItemCode, N'VM 5X6 W' AS PartsCode, N'SUNPRENE TUBE' AS ItemName, N'N1-5' AS LocationCode
    UNION ALL
    SELECT N'00000000247' AS ItemCode, N'VM 5X6B' AS PartsCode, N'SUNPRENE TUBE' AS ItemName, N'N1-1 to 4' AS LocationCode
    UNION ALL
    SELECT N'00000000265' AS ItemCode, N'VM 6.5 X 7.5 DGR' AS PartsCode, N'SUNPRENE TUBE' AS ItemName, N'2FL' AS LocationCode
    UNION ALL
    SELECT N'00000000266' AS ItemCode, N'VM 6.5 X 7.5 L' AS PartsCode, N'SUNPRENE TUBE' AS ItemName, N'N2-2' AS LocationCode
    UNION ALL
    SELECT N'00000000267' AS ItemCode, N'VM 6.5 X 7.5 N6GR' AS PartsCode, N'SUNPRENE TUBE' AS ItemName, N'N2-2' AS LocationCode
    UNION ALL
    SELECT N'00000000248' AS ItemCode, N'VM 6X7B' AS PartsCode, N'SUNPRENE TUBE' AS ItemName, N'N3-5' AS LocationCode
    UNION ALL
    SELECT N'00000000253' AS ItemCode, N'VM 7X8 G' AS PartsCode, N'SUNPRENE TUBE' AS ItemName, N'N2-2' AS LocationCode
    UNION ALL
    SELECT N'00000000254' AS ItemCode, N'VM 7X8 L' AS PartsCode, N'SUNPRENE TUBE' AS ItemName, N'N5-2' AS LocationCode
    UNION ALL
    SELECT N'00000000255' AS ItemCode, N'VM 7X8 N6GR' AS PartsCode, N'SUNPRENE TUBE' AS ItemName, N'N3-4' AS LocationCode
    UNION ALL
    SELECT N'00000000252' AS ItemCode, N'VM 7X8B' AS PartsCode, N'SUNPRENE TUBE' AS ItemName, N'N2-3 to 4' AS LocationCode
    UNION ALL
    SELECT N'00000000281' AS ItemCode, N'VM 8.5X9.5 B' AS PartsCode, N'SUNPRENE TUBE' AS ItemName, N'N2-5' AS LocationCode
    UNION ALL
    SELECT N'00000000257' AS ItemCode, N'VM 8X9 DBR' AS PartsCode, N'SUNPRENE TUBE' AS ItemName, N'N3-2' AS LocationCode
    UNION ALL
    SELECT N'00000000278' AS ItemCode, N'VM 8x9 L' AS PartsCode, N'SUNPRENE TUBE' AS ItemName, N'N3-2' AS LocationCode
    UNION ALL
    SELECT N'00000000256' AS ItemCode, N'VM 8X9B' AS PartsCode, N'SUNPRENE TUBE' AS ItemName, N'N3-3' AS LocationCode
    UNION ALL
    SELECT N'00000000258' AS ItemCode, N'VM 9X10B' AS PartsCode, N'SUNPRENE TUBE' AS ItemName, N'N4-1 to 4' AS LocationCode
    UNION ALL
    SELECT N'00000005383' AS ItemCode, N'VM 8X9 G' AS PartsCode, N'SUNPRENE TUBE' AS ItemName, N'N3-1' AS LocationCode
    UNION ALL
    SELECT N'00000000164' AS ItemCode, N'XAP-07V-1-E' AS PartsCode, N'CONNECTOR' AS ItemName, N'2FL' AS LocationCode
    UNION ALL
    SELECT N'00000000272' AS ItemCode, N'ï¾‚ï½²ï½½ï¾„ï¾ï½­-ï¾Œï¾ž2420F 5-85' AS PartsCode, N'TWIST TUBE' AS ItemName, N'B3-3' AS LocationCode
    UNION ALL
    SELECT N'00000000242' AS ItemCode, N'ï¾ƒï½» NO.51618 19X25 B' AS PartsCode, N'TESA TAPE' AS ItemName, N'P5' AS LocationCode
    UNION ALL
    SELECT N'00000000240' AS ItemCode, N'ï¾ƒï½»ï¾ƒ-ï¾Œï¾Ÿ NO.51036 ï½¸ï¾›' AS PartsCode, N'TESA TAPE' AS ItemName, N'C1' AS LocationCode
    UNION ALL
    SELECT N'0000000006' AS ItemCode, N'CVNS PP3 BKNO 13' AS PartsCode, N'CORRUGATED TUBE' AS ItemName, N'H2' AS LocationCode
    UNION ALL
    SELECT N'00000000160' AS ItemCode, N'7189-0995' AS PartsCode, N'CONNECTOR' AS ItemName, N'B5-4' AS LocationCode
    UNION ALL
    SELECT N'00000000074' AS ItemCode, N'82711-12B10' AS PartsCode, N'CLAMP' AS ItemName, N'2FL-D' AS LocationCode
    UNION ALL
    SELECT N'00000000159' AS ItemCode, N'7188-0996' AS PartsCode, N'CONNECTOR' AS ItemName, N'P22' AS LocationCode
    UNION ALL
    SELECT N'00000000158' AS ItemCode, N'6098-3909' AS PartsCode, N'CONNECTOR' AS ItemName, N'P20-21' AS LocationCode
    UNION ALL
    SELECT N'00000000154' AS ItemCode, N'6098-3869' AS PartsCode, N'CONNECTOR' AS ItemName, N'2FL NM' AS LocationCode
    UNION ALL
    SELECT N'00000000057' AS ItemCode, N'82711-58020' AS PartsCode, N'CLAMP' AS ItemName, N'2FL-D' AS LocationCode
    UNION ALL
    SELECT N'00000000355' AS ItemCode, N'7V4010-002 (7R0102) 02' AS PartsCode, N'QR CODE LABEL' AS ItemName, N'P25' AS LocationCode
    UNION ALL
    SELECT N'00000000356' AS ItemCode, N'7V4020-002 (7R0103) 03' AS PartsCode, N'QR CODE LABEL' AS ItemName, N'K16-B' AS LocationCode
    UNION ALL
    SELECT N'00000000357' AS ItemCode, N'7V4030-002 (7R0104) 04' AS PartsCode, N'QR CODE LABEL' AS ItemName, N'K16-B' AS LocationCode
    UNION ALL
    SELECT N'00000000358' AS ItemCode, N'7V3010-002 (7R0105) 05' AS PartsCode, N'QR CODE LABEL' AS ItemName, N'K16-B' AS LocationCode
    UNION ALL
    SELECT N'00000000359' AS ItemCode, N'7V3020-002 (7R0106) 06' AS PartsCode, N'QR CODE LABEL' AS ItemName, N'K16-B' AS LocationCode
    UNION ALL
    SELECT N'00000005379' AS ItemCode, N'7116-466002' AS PartsCode, N'TERMINAL' AS ItemName, N'C2-A' AS LocationCode
    UNION ALL
    SELECT N'00000005378' AS ItemCode, N'7283-7596' AS PartsCode, N'CONNECTOR' AS ItemName, N'A2-3' AS LocationCode
    UNION ALL
    SELECT N'00000000043' AS ItemCode, N'82711-1B090' AS PartsCode, N'CLAMP' AS ItemName, N'2FL-D' AS LocationCode
    UNION ALL
    SELECT N'00000005377' AS ItemCode, N'6098-3871' AS PartsCode, N'CONNECTOR' AS ItemName, N'B6-4' AS LocationCode
    UNION ALL
    SELECT N'00000005392' AS ItemCode, N'ï¾‚ï½²ï½½ï¾„ï¾ï½­-ï¾Œï¾ž2420F 5-182' AS PartsCode, N'TWIST TUBE' AS ItemName, N'D4' AS LocationCode
    UNION ALL
    SELECT N'00000005393' AS ItemCode, N'ï¾‚ï½²ï½½ï¾„ï¾ï½­-ï¾Œï¾ž2420F 5-189' AS PartsCode, N'TWIST TUBE' AS ItemName, N'D7' AS LocationCode
    UNION ALL
    SELECT N'00000005394' AS ItemCode, N'ï¾‚ï½²ï½½ï¾„ï¾ï½­-ï¾Œï¾ž2420F 5-257' AS PartsCode, N'TWIST TUBE' AS ItemName, N'D2' AS LocationCode
    UNION ALL
    SELECT N'00000005384' AS ItemCode, N'VM 3X4 W' AS PartsCode, N'SUNPRENE TUBE' AS ItemName, N'N3-1' AS LocationCode
    UNION ALL
    SELECT N'00000005382' AS ItemCode, N'VM 7X8 DBR' AS PartsCode, N'SUNPRENE TUBE' AS ItemName, N'N2-1' AS LocationCode
    UNION ALL
    SELECT N'00000005389' AS ItemCode, N'7V8120-0020 (7L0141-702) 41' AS PartsCode, N'QR CODE LABEL' AS ItemName, N'K16-B' AS LocationCode
    UNION ALL
    SELECT N'00000005390' AS ItemCode, N'7V8110-0020 (7L0140-702) 40' AS PartsCode, N'QR CODE LABEL' AS ItemName, N'K16-A' AS LocationCode
    UNION ALL
    SELECT N'00000005391' AS ItemCode, N'7V8130-0020 (7L0139-702) 39' AS PartsCode, N'QR CODE LABEL' AS ItemName, N'K16-B' AS LocationCode
    UNION ALL
    SELECT N'00000005385' AS ItemCode, N'VM 3X4 DBR' AS PartsCode, N'SUNPRENE TUBE' AS ItemName, N'2FL NM' AS LocationCode
    UNION ALL
    SELECT N'00000005387' AS ItemCode, N'VM 8.5X9.5 L' AS PartsCode, N'SUNPRENE TUBE' AS ItemName, N'2FL NM' AS LocationCode
    UNION ALL
    SELECT N'00000005386' AS ItemCode, N'VM 8.5X9.5 N6GR' AS PartsCode, N'SUNPRENE TUBE' AS ItemName, N'2FL NM' AS LocationCode
    UNION ALL
    SELECT N'00000005388' AS ItemCode, N'VM 8.5X9.5 DBR' AS PartsCode, N'SUNPRENE TUBE' AS ItemName, N'2FL NM' AS LocationCode
    UNION ALL
    SELECT N'00000000306' AS ItemCode, N'VM 8X9 DGR' AS PartsCode, N'SUNPRENE TUBE' AS ItemName, N'N5-2' AS LocationCode
    UNION ALL
    SELECT N'00000005380' AS ItemCode, N'AVSS 0.3 LG/R' AS PartsCode, N'IEWP WIRE' AS ItemName, N'M1-5' AS LocationCode
    UNION ALL
    SELECT N'00000005381' AS ItemCode, N'AVSS 0.3 G/B' AS PartsCode, N'IEWP WIRE' AS ItemName, N'M1-4' AS LocationCode
    UNION ALL
    SELECT N'00000005376' AS ItemCode, N'6098-3870' AS PartsCode, N'CONNECTOR' AS ItemName, N'2FL' AS LocationCode
    UNION ALL
    SELECT N'00000009378' AS ItemCode, N'6188-0266' AS PartsCode, N'CONNECTOR' AS ItemName, N'B5-4' AS LocationCode
    UNION ALL
    SELECT N'00000009213' AS ItemCode, N'525200-2M' AS PartsCode, N'TERMINAL' AS ItemName, N'C3-B' AS LocationCode
    UNION ALL
    SELECT N'00000009214' AS ItemCode, N'553800-0' AS PartsCode, N'TERMINAL' AS ItemName, N'C2' AS LocationCode
    UNION ALL
    SELECT N'00000008610' AS ItemCode, N'82711-33650 (730481-5660)' AS PartsCode, N'CLAMP' AS ItemName, N'2FL-F' AS LocationCode
    UNION ALL
    SELECT N'00000000091' AS ItemCode, N'6188-0706' AS PartsCode, N'CONNECTOR' AS ItemName, N'2FL NM' AS LocationCode
    UNION ALL
    SELECT N'00000001794' AS ItemCode, N'NSHR-09V-S' AS PartsCode, N'CONNECTOR' AS ItemName, N'B3-4' AS LocationCode
    UNION ALL
    SELECT N'00000001236' AS ItemCode, N'SSHL-003T-P0.2' AS PartsCode, N'TERMINAL' AS ItemName, N'C3-B' AS LocationCode
    UNION ALL
    SELECT N'00000001206' AS ItemCode, N'BEAMEX NF 0.035 B' AS PartsCode, N'WIRE' AS ItemName, N'2FL NM' AS LocationCode
    UNION ALL
    SELECT N'00000001435' AS ItemCode, N'BEAMEX NF 0.035 BR' AS PartsCode, N'WIRE' AS ItemName, N'2FL NM' AS LocationCode
    UNION ALL
    SELECT N'00000001165' AS ItemCode, N'BEAMEX NF 0.035 G' AS PartsCode, N'WIRE' AS ItemName, N'2FL NM' AS LocationCode
    UNION ALL
    SELECT N'00000001530' AS ItemCode, N'BEAMEX NF 0.035 GR' AS PartsCode, N'WIRE' AS ItemName, N'2FL NM' AS LocationCode
    UNION ALL
    SELECT N'00000005119' AS ItemCode, N'BEAMEX NF 0.035 L' AS PartsCode, N'WIRE' AS ItemName, N'2FL NM' AS LocationCode
    UNION ALL
    SELECT N'00000001230' AS ItemCode, N'BEAMEX NF 0.035 OR' AS PartsCode, N'WIRE' AS ItemName, N'2FL NM' AS LocationCode
    UNION ALL
    SELECT N'00000008993' AS ItemCode, N'BEAMEX NF 0.035 R' AS PartsCode, N'WIRE' AS ItemName, N'2FL NM' AS LocationCode
    UNION ALL
    SELECT N'00000001437' AS ItemCode, N'BEAMEX NF 0.035 W' AS PartsCode, N'WIRE' AS ItemName, N'2FL NM' AS LocationCode
    UNION ALL
    SELECT N'00000001229' AS ItemCode, N'BEAMEX NF 0.035 Y' AS PartsCode, N'WIRE' AS ItemName, N'2FL NM' AS LocationCode
    UNION ALL
    SELECT N'00000009125' AS ItemCode, N'VM 4X4.6' AS PartsCode, N'SUNPRENE TUBE' AS ItemName, N'2FL NM' AS LocationCode
    UNION ALL
    SELECT N'00000009404' AS ItemCode, N'STNS PVC90 WHNO 03,0X04,0' AS PartsCode, N'PVC TUBE' AS ItemName, N'G8' AS LocationCode
    UNION ALL
    SELECT N'00000009405' AS ItemCode, N'PVC90 05,5X06,5' AS PartsCode, N'PVC TUBE' AS ItemName, N'G8' AS LocationCode
    UNION ALL
    SELECT N'00000009416' AS ItemCode, N'RT18RSF' AS PartsCode, N'CLAMP' AS ItemName, N'2FL-D' AS LocationCode
    UNION ALL
    SELECT N'00000009644' AS ItemCode, N'VM 5X6 L' AS PartsCode, N'SUNPRENE TUBE' AS ItemName, N'N5-1' AS LocationCode
    UNION ALL
    SELECT N'00000009731' AS ItemCode, N'4A1720-0000' AS PartsCode, N'CONNECTOR' AS ItemName, N'P18' AS LocationCode
    UNION ALL
    SELECT N'00000009732' AS ItemCode, N'4A1810-0000' AS PartsCode, N'CONNECTOR' AS ItemName, N'2FL' AS LocationCode
    UNION ALL
    SELECT N'00000009733' AS ItemCode, N'4A1820-0000' AS PartsCode, N'CONNECTOR' AS ItemName, N'D3' AS LocationCode
    UNION ALL
    SELECT N'00000010006' AS ItemCode, N'7V3200-0020 (7R0116-702) 16' AS PartsCode, N'QR CODE LABEL' AS ItemName, N'P24' AS LocationCode
    UNION ALL
    SELECT N'00000010007' AS ItemCode, N'7V4240-0020 (7R0117-702) 17' AS PartsCode, N'QR CODE LABEL' AS ItemName, N'P24' AS LocationCode
    UNION ALL
    SELECT N'00000010008' AS ItemCode, N'7V4200-0020A (7R0118-702) 18' AS PartsCode, N'QR CODE LABEL' AS ItemName, N'P24' AS LocationCode
    UNION ALL
    SELECT N'00000010083' AS ItemCode, N'SPA-001T-P0.5' AS PartsCode, N'TERMINAL' AS ItemName, N'I10-2' AS LocationCode
    UNION ALL
    SELECT N'00000010081' AS ItemCode, N'PARP-03V' AS PartsCode, N'CONNECTOR' AS ItemName, N'B3-2' AS LocationCode
    UNION ALL
    SELECT N'00000010082' AS ItemCode, N'PARP-03V-E' AS PartsCode, N'CONNECTOR' AS ItemName, N'B6-4' AS LocationCode
    UNION ALL
    SELECT N'00000010084' AS ItemCode, N'PMS-03V-S' AS PartsCode, N'RETAINER' AS ItemName, N'B4-2' AS LocationCode
    UNION ALL
    SELECT N'00000010080' AS ItemCode, N'4A1330-0000' AS PartsCode, N'CONNECTOR' AS ItemName, N'P28' AS LocationCode
    UNION ALL
    SELECT N'00000010086' AS ItemCode, N'VM 4.5X5.5 DBR' AS PartsCode, N'SUNPRENE TUBE' AS ItemName, N'N5-3' AS LocationCode
    UNION ALL
    SELECT N'00000010085' AS ItemCode, N'VM 4X5 B' AS PartsCode, N'SUNPRENE TUBE' AS ItemName, N'N5-4' AS LocationCode
    UNION ALL
    SELECT N'00000010087' AS ItemCode, N'VM 4.5X5.5 L' AS PartsCode, N'SUNPRENE TUBE' AS ItemName, N'N5-4' AS LocationCode
    UNION ALL
    SELECT N'00000009776' AS ItemCode, N'7K0580-0051' AS PartsCode, N'MAGNET' AS ItemName, N'2FL-B' AS LocationCode
    UNION ALL
    SELECT N'00000000369' AS ItemCode, N'SUNPRENE TUBE 11X12 B' AS PartsCode, N'SUNPRENE TUBE' AS ItemName, N'2FL' AS LocationCode
    UNION ALL
    SELECT N'00000010113' AS ItemCode, N'VM 4.5X5.5 N6GR' AS PartsCode, N'SUNPRENE TUBE' AS ItemName, N'N5-4' AS LocationCode
    UNION ALL
    SELECT N'00000010114' AS ItemCode, N'VM 4.5X5.5 W' AS PartsCode, N'SUNPRENE TUBE' AS ItemName, N'N2-1' AS LocationCode
    UNION ALL
    SELECT N'00000009558' AS ItemCode, N'SRA-01T-5B' AS PartsCode, N'TERMINAL' AS ItemName, N'C2-B' AS LocationCode
    UNION ALL
    SELECT N'00000010372' AS ItemCode, N'7K0580-0021' AS PartsCode, N'HOUSING' AS ItemName, N'2FL-C' AS LocationCode
    UNION ALL
    SELECT N'00000010373' AS ItemCode, N'7286-4097' AS PartsCode, N'CONNECTOR' AS ItemName, N'B4-2' AS LocationCode
    UNION ALL
    SELECT N'00000010374' AS ItemCode, N'7283-1027' AS PartsCode, N'CONNECTOR' AS ItemName, N'B5-4' AS LocationCode
    UNION ALL
    SELECT N'00000010361' AS ItemCode, N'PVC90 03,0X04,0 RLX 800M' AS PartsCode, N'PVC TUBE' AS ItemName, N'F6' AS LocationCode
    UNION ALL
    SELECT N'00000010362' AS ItemCode, N'PVC90 08,5X09,5 RLX 320M IY' AS PartsCode, N'PVC TUBE' AS ItemName, N'F5' AS LocationCode
    UNION ALL
    SELECT N'00000000280' AS ItemCode, N'VM 6.5X7.5 B' AS PartsCode, N'SUNPRENE TUBE' AS ItemName, N'N3-2' AS LocationCode
    UNION ALL
    SELECT N'00000010492' AS ItemCode, N'4A1812-0000' AS PartsCode, N'CONNECTOR' AS ItemName, N'G4' AS LocationCode
    UNION ALL
    SELECT N'00000010480' AS ItemCode, N'PVC90 04,5X05,5 RLX 533M' AS PartsCode, N'PVC TUBE' AS ItemName, N'F5' AS LocationCode
    UNION ALL
    SELECT N'00000010009' AS ItemCode, N'7V3240-0020 (7R0119-702) 19' AS PartsCode, N'QR CODE LABEL' AS ItemName, N'P24' AS LocationCode
    UNION ALL
    SELECT N'00000010805' AS ItemCode, N'82712-12080' AS PartsCode, N'CLAMP' AS ItemName, N'2FL-D' AS LocationCode
    UNION ALL
    SELECT N'00000010879' AS ItemCode, N'7L0166 (7V7260-0020) 66' AS PartsCode, N'QR CODE LABEL' AS ItemName, N'P23' AS LocationCode
    UNION ALL
    SELECT N'00000010878' AS ItemCode, N'7L0165 (7V8260-0020) 65' AS PartsCode, N'QR CODE LABEL' AS ItemName, N'P25' AS LocationCode
    UNION ALL
    SELECT N'00000010880' AS ItemCode, N'7L0167 (7V8280-0020) 67' AS PartsCode, N'QR CODE LABEL' AS ItemName, N'P25' AS LocationCode
    UNION ALL
    SELECT N'00000010976' AS ItemCode, N'2420F 5-81' AS PartsCode, N'TWIST TUBE' AS ItemName, N'G3' AS LocationCode
    UNION ALL
    SELECT N'00000011345' AS ItemCode, N'AVSSF 0.5F G' AS PartsCode, N'IEWP WIRE' AS ItemName, N'M1-1' AS LocationCode
    UNION ALL
    SELECT N'00000011347' AS ItemCode, N'AVSSF 0.5F L' AS PartsCode, N'IEWP WIRE' AS ItemName, N'M1-2' AS LocationCode
    UNION ALL
    SELECT N'00000011346' AS ItemCode, N'AVSSF 0.5F W' AS PartsCode, N'IEWP WIRE' AS ItemName, N'M1-3' AS LocationCode
    UNION ALL
    SELECT N'00000011103' AS ItemCode, N'SC BLACK 5X50X41MM' AS PartsCode, N'POLYURETHANE FOAM' AS ItemName, N'G2' AS LocationCode
    UNION ALL
    SELECT N'00000011111' AS ItemCode, N'6189-1132' AS PartsCode, N'CONNECTOR' AS ItemName, N'A5-3' AS LocationCode
    UNION ALL
    SELECT N'00000000096' AS ItemCode, N'6189-1129' AS PartsCode, N'CONNECTOR' AS ItemName, N'A1-3' AS LocationCode
    UNION ALL
    SELECT N'00000011122' AS ItemCode, N'540223-0151' AS PartsCode, N'GROMMET' AS ItemName, N'J' AS LocationCode
    UNION ALL
    SELECT N'00000011123' AS ItemCode, N'540223-0260' AS PartsCode, N'GROMMET' AS ItemName, N'J' AS LocationCode
    UNION ALL
    SELECT N'00000011441' AS ItemCode, N'540223-0220' AS PartsCode, N'GROMMET' AS ItemName, N'J' AS LocationCode
    UNION ALL
    SELECT N'00000011434' AS ItemCode, N'XAP-02V-1' AS PartsCode, N'CONNECTOR' AS ItemName, N'J' AS LocationCode
    UNION ALL
    SELECT N'00000011577' AS ItemCode, N'540201-0650' AS PartsCode, N'CONNECTOR' AS ItemName, N'J' AS LocationCode
    UNION ALL
    SELECT N'00000011576' AS ItemCode, N'K9320H-9104-L' AS PartsCode, N'CONNECTOR' AS ItemName, N'J' AS LocationCode
    UNION ALL
    SELECT N'00000011550' AS ItemCode, N'MX19002S51' AS PartsCode, N'CONNECTOR' AS ItemName, N'J' AS LocationCode
    UNION ALL
    SELECT N'00000011578' AS ItemCode, N'57B495-0120' AS PartsCode, N'CONNECTOR' AS ItemName, N'J' AS LocationCode
    UNION ALL
    SELECT N'00000011541' AS ItemCode, N'4A1300-0000' AS PartsCode, N'CONNECTOR' AS ItemName, N'J' AS LocationCode
    UNION ALL
    SELECT N'00000011545' AS ItemCode, N'4F1120-7010' AS PartsCode, N'CONNECTOR' AS ItemName, N'J' AS LocationCode
    UNION ALL
    SELECT N'00000011546' AS ItemCode, N'4F1120-7020' AS PartsCode, N'CONNECTOR' AS ItemName, N'J' AS LocationCode
    UNION ALL
    SELECT N'00000011429' AS ItemCode, N'PHR-4' AS PartsCode, N'CONNECTOR' AS ItemName, N'J' AS LocationCode
    UNION ALL
    SELECT N'00000011430' AS ItemCode, N'PAMURP-02V-K-A' AS PartsCode, N'CONNECTOR' AS ItemName, N'J' AS LocationCode
    UNION ALL
    SELECT N'00000011574' AS ItemCode, N'CA01A6-04N0-01' AS PartsCode, N'CONNECTOR' AS ItemName, N'J' AS LocationCode
    UNION ALL
    SELECT N'00000011543' AS ItemCode, N'4F0730-0010' AS PartsCode, N'CONNECTOR' AS ItemName, N'J' AS LocationCode
    UNION ALL
    SELECT N'00000011540' AS ItemCode, N'201-0650' AS PartsCode, N'COVER' AS ItemName, N'J' AS LocationCode
    UNION ALL
    SELECT N'00000011544' AS ItemCode, N'4F0730-0020' AS PartsCode, N'RETAINER' AS ItemName, N'J' AS LocationCode
    UNION ALL
    SELECT N'00000011442' AS ItemCode, N'540223-0250' AS PartsCode, N'GROMMET' AS ItemName, N'J' AS LocationCode
    UNION ALL
    SELECT N'00000011113' AS ItemCode, N'7157-8761' AS PartsCode, N'WIRE SEAL' AS ItemName, N'P25' AS LocationCode
    UNION ALL
    SELECT N'00000000290' AS ItemCode, N'7165-0815' AS PartsCode, N'WIRE SEAL' AS ItemName, N'A2-4' AS LocationCode
    UNION ALL
    SELECT N'00000011112' AS ItemCode, N'7282-706240' AS PartsCode, N'CONNECTOR' AS ItemName, N'A5-4' AS LocationCode
    UNION ALL
    SELECT N'00000011125' AS ItemCode, N'7R0152 (7V3410-0020) 52' AS PartsCode, N'QR CODE LABEL' AS ItemName, N'K16-A' AS LocationCode
    UNION ALL
    SELECT N'00000011126' AS ItemCode, N'7R0153 (7V3420-0020) 53' AS PartsCode, N'QR CODE LABEL' AS ItemName, N'P25' AS LocationCode
    UNION ALL
    SELECT N'00000011089' AS ItemCode, N'CAVUS 0.3 B' AS PartsCode, N'CAVUS WIRE' AS ItemName, N'M7-1' AS LocationCode
    UNION ALL
    SELECT N'00000011090' AS ItemCode, N'CAVUS 0.3 B/R' AS PartsCode, N'CAVUS WIRE' AS ItemName, N'M7-2' AS LocationCode
    UNION ALL
    SELECT N'00000011091' AS ItemCode, N'CAVUS 0.3 R/B' AS PartsCode, N'CAVUS WIRE' AS ItemName, N'M7-3' AS LocationCode
    UNION ALL
    SELECT N'00000011092' AS ItemCode, N'CAVUS 0.3 BR/W' AS PartsCode, N'CAVUS WIRE' AS ItemName, N'M7-4' AS LocationCode
    UNION ALL
    SELECT N'00000011093' AS ItemCode, N'CAVUS 0.3 Y/G' AS PartsCode, N'CAVUS WIRE' AS ItemName, N'M7-5' AS LocationCode
    UNION ALL
    SELECT N'00000011523' AS ItemCode, N'CAVUS 0.3 G' AS PartsCode, N'CAVUS WIRE' AS ItemName, N'M8-1' AS LocationCode
    UNION ALL
    SELECT N'00000011524' AS ItemCode, N'CAVUS 0.3 GR' AS PartsCode, N'CAVUS WIRE' AS ItemName, N'M8-2' AS LocationCode
    UNION ALL
    SELECT N'00000011525' AS ItemCode, N'CAVUS 0.3 L' AS PartsCode, N'CAVUS WIRE' AS ItemName, N'M8-3' AS LocationCode
    UNION ALL
    SELECT N'00000011526' AS ItemCode, N'CAVUS 0.3 LG' AS PartsCode, N'CAVUS WIRE' AS ItemName, N'M8-4' AS LocationCode
    UNION ALL
    SELECT N'00000011527' AS ItemCode, N'CAVUS 0.3 OR' AS PartsCode, N'CAVUS WIRE' AS ItemName, N'M8-5' AS LocationCode
    UNION ALL
    SELECT N'00000011528' AS ItemCode, N'CAVUS 0.3 P' AS PartsCode, N'CAVUS WIRE' AS ItemName, N'M9-1' AS LocationCode
    UNION ALL
    SELECT N'00000011529' AS ItemCode, N'CAVUS 0.3 R' AS PartsCode, N'CAVUS WIRE' AS ItemName, N'M9-2' AS LocationCode
    UNION ALL
    SELECT N'00000011530' AS ItemCode, N'CAVUS 0.3 SL' AS PartsCode, N'CAVUS WIRE' AS ItemName, N'M9-3' AS LocationCode
    UNION ALL
    SELECT N'00000011531' AS ItemCode, N'CAVUS 0.3 V' AS PartsCode, N'CAVUS WIRE' AS ItemName, N'M9-4' AS LocationCode
    UNION ALL
    SELECT N'00000011532' AS ItemCode, N'CAVUS 0.3 W' AS PartsCode, N'CAVUS WIRE' AS ItemName, N'M9-5' AS LocationCode
    UNION ALL
    SELECT N'00000011533' AS ItemCode, N'CAVUS 0.3 Y' AS PartsCode, N'CAVUS WIRE' AS ItemName, N'M10-1' AS LocationCode
    UNION ALL
    SELECT N'00000011534' AS ItemCode, N'CAVUS 0.3 R/W' AS PartsCode, N'CAVUS WIRE' AS ItemName, N'M10-2' AS LocationCode
    UNION ALL
    SELECT N'00000011535' AS ItemCode, N'CAVUS 0.3 W/B' AS PartsCode, N'CAVUS WIRE' AS ItemName, N'M10-3' AS LocationCode
    UNION ALL
    SELECT N'00000011536' AS ItemCode, N'CAVUS 0.3 Y/B' AS PartsCode, N'CAVUS WIRE' AS ItemName, N'M10-4' AS LocationCode
    UNION ALL
    SELECT N'00000011569' AS ItemCode, N'CAVUS 0.3 G/W' AS PartsCode, N'CAVUS WIRE' AS ItemName, N'M10-5' AS LocationCode
    UNION ALL
    SELECT N'00000011570' AS ItemCode, N'CAVUS 0.3 R/L' AS PartsCode, N'CAVUS WIRE' AS ItemName, N'M11-1' AS LocationCode
    UNION ALL
    SELECT N'00000011571' AS ItemCode, N'CAVUS 0.3 W/G' AS PartsCode, N'CAVUS WIRE' AS ItemName, N'M11-2' AS LocationCode
    UNION ALL
    SELECT N'00000011344' AS ItemCode, N'4A1232-0000' AS PartsCode, N'CONNECTOR' AS ItemName, N'P19' AS LocationCode
    UNION ALL
    SELECT N'00000011588' AS ItemCode, N'730415-0610 (82711-2C190)' AS PartsCode, N'CLAMP' AS ItemName, N'2FL-D' AS LocationCode
    UNION ALL
    SELECT N'00000011724' AS ItemCode, N'PVC TUBE 4X5 (GR)' AS PartsCode, N'WAKOREPKO PVC TUBE' AS ItemName, N'2FL' AS LocationCode
    UNION ALL
    SELECT N'00000011725' AS ItemCode, N'PVC TUBE 9X10 (GR)' AS PartsCode, N'WAKOREPKO PVC TUBE' AS ItemName, N'2FL' AS LocationCode
    UNION ALL
    SELECT N'00000011726' AS ItemCode, N'PVC TUBE 10X11 (GR)' AS PartsCode, N'WAKOREPKO PVC TUBE' AS ItemName, N'2FL' AS LocationCode
    UNION ALL
    SELECT N'00000011812' AS ItemCode, N'6098-5671' AS PartsCode, N'CONNECTOR' AS ItemName, N'A4-2 to 3' AS LocationCode
    UNION ALL
    SELECT N'00000011324' AS ItemCode, N'505151-0301' AS PartsCode, N'CONNECTOR' AS ItemName, N'2FL NM' AS LocationCode
    UNION ALL
    SELECT N'00000011325' AS ItemCode, N'505151-0201' AS PartsCode, N'CONNECTOR' AS ItemName, N'2FL NM' AS LocationCode
    UNION ALL
    SELECT N'00000011326' AS ItemCode, N'505152-0300' AS PartsCode, N'RETAINER' AS ItemName, N'2FL NM' AS LocationCode
    UNION ALL
    SELECT N'00000011327' AS ItemCode, N'505152-0200' AS PartsCode, N'RETAINER' AS ItemName, N'2FL NM' AS LocationCode
    UNION ALL
    SELECT N'00000011328' AS ItemCode, N'505153-8000' AS PartsCode, N'TERMINAL' AS ItemName, N'D6' AS LocationCode
    UNION ALL
    SELECT N'00000011420' AS ItemCode, N'AVSS 0.3 B/L' AS PartsCode, N'IEWP WIRE' AS ItemName, N'M2-1' AS LocationCode
    UNION ALL
    SELECT N'00000011421' AS ItemCode, N'AVSS 0.3 GR/R' AS PartsCode, N'IEWP WIRE' AS ItemName, N'M2-2' AS LocationCode
    UNION ALL
    SELECT N'00000011427' AS ItemCode, N'502351-0200' AS PartsCode, N'CONNECTOR' AS ItemName, N'J' AS LocationCode
    UNION ALL
    SELECT N'00000011425' AS ItemCode, N'50372-8000' AS PartsCode, N'TERMINAL' AS ItemName, N'D6' AS LocationCode
    UNION ALL
    SELECT N'00000011422' AS ItemCode, N'51065-0400' AS PartsCode, N'CONNECTOR' AS ItemName, N'J' AS LocationCode
    UNION ALL
    SELECT N'00000011424' AS ItemCode, N'560085-0101' AS PartsCode, N'TERMINAL' AS ItemName, N'D6' AS LocationCode
    UNION ALL
    SELECT N'00000011440' AS ItemCode, N'BEAMEX ER500 0.3 (red)' AS PartsCode, N'COPPER WIRE' AS ItemName, N'2FL NM' AS LocationCode
    UNION ALL
    SELECT N'00000011439' AS ItemCode, N'BEAMEX ER500 0.3 (black)' AS PartsCode, N'COPPER WIRE' AS ItemName, N'2FL NM' AS LocationCode
    UNION ALL
    SELECT N'00000011554' AS ItemCode, N'2420F 5-130' AS PartsCode, N'TWIST TUBE' AS ItemName, N'P' AS LocationCode
    UNION ALL
    SELECT N'00000011555' AS ItemCode, N'2420F 5-145' AS PartsCode, N'TWIST TUBE' AS ItemName, N'P' AS LocationCode
    UNION ALL
    SELECT N'00000012061' AS ItemCode, N'1473793-1' AS PartsCode, N'CONNECTOR' AS ItemName, N'B4-1' AS LocationCode
    UNION ALL
    SELECT N'00000012062' AS ItemCode, N'1376352-1' AS PartsCode, N'CONNECTOR' AS ItemName, N'B3-1' AS LocationCode
    UNION ALL
    SELECT N'00000012063' AS ItemCode, N'6098-3893' AS PartsCode, N'CONNECTOR' AS ItemName, N'P25' AS LocationCode
    UNION ALL
    SELECT N'00000011551' AS ItemCode, N'MX19S10K451' AS PartsCode, N'TERMINAL' AS ItemName, N'D5' AS LocationCode
    UNION ALL
    SELECT N'00000011549' AS ItemCode, N'CA01C6-010A' AS PartsCode, N'TERMINAL' AS ItemName, N'D5' AS LocationCode
    UNION ALL
    SELECT N'00000011539' AS ItemCode, N'171588-M2' AS PartsCode, N'TERMINAL' AS ItemName, N'D5' AS LocationCode
    UNION ALL
    SELECT N'00000011547' AS ItemCode, N'4R2360-0000' AS PartsCode, N'TERMINAL' AS ItemName, N'D5' AS LocationCode
    UNION ALL
    SELECT N'00000001540' AS ItemCode, N'SJN-001PT-0.9' AS PartsCode, N'TERMINAL' AS ItemName, N'D6' AS LocationCode
    UNION ALL
    SELECT N'00000011329' AS ItemCode, N'02P-SJN' AS PartsCode, N'CONNECTOR' AS ItemName, N'B4-4' AS LocationCode
    UNION ALL
    SELECT N'00000011330' AS ItemCode, N'06P-SJN' AS PartsCode, N'CONNECTOR' AS ItemName, N'B3-2' AS LocationCode
    UNION ALL
    SELECT N'00000011431' AS ItemCode, N'SPAMU-01T-M0.5' AS PartsCode, N'TERMINAL' AS ItemName, N'D5' AS LocationCode
    UNION ALL
    SELECT N'00000011432' AS ItemCode, N'SPH-001T-P0.5L' AS PartsCode, N'TERMINAL' AS ItemName, N'D5' AS LocationCode
    UNION ALL
    SELECT N'00000011548' AS ItemCode, N'7165-1312' AS PartsCode, N'WIRE SEAL' AS ItemName, N'J' AS LocationCode
    UNION ALL
    SELECT N'00000011579' AS ItemCode, N'57B495-7090' AS PartsCode, N'CAMERA CORD' AS ItemName, N'J' AS LocationCode
    UNION ALL
    SELECT N'00000011580' AS ItemCode, N'57B599-7060' AS PartsCode, N'CAMERA CORD' AS ItemName, N'J' AS LocationCode
    UNION ALL
    SELECT N'00000012774' AS ItemCode, N'(PVC) 4X5-95 GR' AS PartsCode, N'PVC TUBE' AS ItemName, N'J' AS LocationCode
    UNION ALL
    SELECT N'00000012775' AS ItemCode, N'(PVC) 9X10-124 GR' AS PartsCode, N'PVC TUBE' AS ItemName, N'J' AS LocationCode
    UNION ALL
    SELECT N'00000012776' AS ItemCode, N'(PVC) 9X10-223 GR' AS PartsCode, N'PVC TUBE' AS ItemName, N'J' AS LocationCode
    UNION ALL
    SELECT N'00000012777' AS ItemCode, N'(PVC) 10X11-134 GR' AS PartsCode, N'PVC TUBE' AS ItemName, N'J' AS LocationCode
    UNION ALL
    SELECT N'00000012937' AS ItemCode, N'SV 4X5 GR' AS PartsCode, N'PVC TUBE' AS ItemName, N'J' AS LocationCode
    UNION ALL
    SELECT N'00000012938' AS ItemCode, N'SV 9X10 GR' AS PartsCode, N'PVC TUBE' AS ItemName, N'N5-1' AS LocationCode
    UNION ALL
    SELECT N'00000012939' AS ItemCode, N'SV 10X11 GR' AS PartsCode, N'PVC TUBE' AS ItemName, N'N5-1' AS LocationCode
    UNION ALL
    SELECT N'00000011572' AS ItemCode, N'172951-M2' AS PartsCode, N'TERMINAL' AS ItemName, N'D5' AS LocationCode
    UNION ALL
    SELECT N'00000011573' AS ItemCode, N'172952-M2' AS PartsCode, N'TERMINAL' AS ItemName, N'D5' AS LocationCode
    UNION ALL
    SELECT N'00000011575' AS ItemCode, N'K9320H-9102-S' AS PartsCode, N'CONNECTOR' AS ItemName, N'J' AS LocationCode
    UNION ALL
    SELECT N'00000011542' AS ItemCode, N'4B1150-0000' AS PartsCode, N'CONNECTOR' AS ItemName, N'J' AS LocationCode
    UNION ALL
    SELECT N'00000012578' AS ItemCode, N'PVC TUBE 7X8 (GR)' AS PartsCode, N'WAKOREPKO PVC TUBE' AS ItemName, N'2FL' AS LocationCode
    UNION ALL
    SELECT N'00000013058' AS ItemCode, N'SV 7X8 GR' AS PartsCode, N'PVC TUBE' AS ItemName, N'N5-3' AS LocationCode
    UNION ALL
    SELECT N'00000012960' AS ItemCode, N'5X25X50MM' AS PartsCode, N'URETHANE FOAM' AS ItemName, N'WH CABINET' AS LocationCode
    UNION ALL
    SELECT N'00000013023' AS ItemCode, N'MW805PA2535' AS PartsCode, N'LAMINATE FILM' AS ItemName, N'J' AS LocationCode
    UNION ALL
    SELECT N'00000013559' AS ItemCode, N'VM TUBE 2.5X3.5' AS PartsCode, N'SUNPRENE TUBE' AS ItemName, N'U' AS LocationCode
    UNION ALL
    SELECT N'00000013701' AS ItemCode, N'A 0.3(7/7/0.1) BLUE' AS PartsCode, N'IRRAX WIRE' AS ItemName, N'K13' AS LocationCode
    UNION ALL
    SELECT N'00000000413' AS ItemCode, N'A 0.3(7/7/0.1) YELLOW' AS PartsCode, N'IRRAX WIRE' AS ItemName, N'K13' AS LocationCode
    UNION ALL
    SELECT N'00000013773' AS ItemCode, N'VM TUBE 3.5X4.1' AS PartsCode, N'VM TUBE' AS ItemName, N'T' AS LocationCode
    UNION ALL
    SELECT N'00000013843' AS ItemCode, N'6098-3804' AS PartsCode, N'CONNECTOR' AS ItemName, N'P' AS LocationCode
    UNION ALL
    SELECT N'00000001982' AS ItemCode, N'7165-1198' AS PartsCode, N'WIRE SEAL' AS ItemName, N'P25' AS LocationCode
    UNION ALL
    SELECT N'00000012428' AS ItemCode, N'AVSS 0.3 BR/W' AS PartsCode, N'WIRE' AS ItemName, N'M2-4' AS LocationCode
    UNION ALL
    SELECT N'00000012429' AS ItemCode, N'AVSS 0.3 B/R' AS PartsCode, N'WIRE' AS ItemName, N'M2-5' AS LocationCode
    UNION ALL
    SELECT N'00000014056' AS ItemCode, N'7282-8120' AS PartsCode, N'CONNECTOR' AS ItemName, N'P' AS LocationCode
    UNION ALL
    SELECT N'00000014127' AS ItemCode, N'6098-6053' AS PartsCode, N'CONNECTOR' AS ItemName, N'P26' AS LocationCode
    UNION ALL
    SELECT N'00000014196' AS ItemCode, N'540223-0270' AS PartsCode, N'GROMMET' AS ItemName, N'' AS LocationCode
    UNION ALL
    SELECT N'00000014167' AS ItemCode, N'156-01798' AS PartsCode, N'CLAMP' AS ItemName, N'' AS LocationCode
    UNION ALL
    SELECT N'00000010010' AS ItemCode, N'7V3180-0020 (7R0120-702) 20' AS PartsCode, N'QR CODE LABEL' AS ItemName, N'P24' AS LocationCode
) AS S
ON T.ItemCode = S.ItemCode
WHEN MATCHED THEN
    UPDATE SET
        PartsCode = S.PartsCode,
        ItemName = S.ItemName,
        LocationCode = S.LocationCode,
        IsActive = 1,
        UpdatedAt = GETDATE(),
        UpdatedByUsername = 'seed'
WHEN NOT MATCHED THEN
    INSERT (ItemCode, PartsCode, ItemName, LocationCode, IsActive, UpdatedByUsername)
    VALUES (S.ItemCode, S.PartsCode, S.ItemName, S.LocationCode, 1, 'seed');
GO
