package tests.INS;

import org.junit.jupiter.api.DisplayName;
import org.junit.jupiter.api.Test;
import tests.base.BaseIngest;

public class INSIngestHierarchyTest extends BaseIngest {

    @Test
    @DisplayName("Ingest INS file: testeINSHIERARCHY")
    void shouldIngestTesteHierarchy() throws InterruptedException {
        ingestSpecificINS("INS-NHANES-2017-2018.xlsx");
    }
}
