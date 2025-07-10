package tests.INS;

import org.junit.jupiter.api.DisplayName;
import org.junit.jupiter.api.Test;
import tests.base.BaseIngest;

public class INSIngestNormalTest extends BaseIngest {

    @Test
    @DisplayName("Ingest INS file: testeINS")
    void shouldIngestTesteINS() throws InterruptedException {
        ingestSpecificINS("INS-NHANES-2017-2018-HIERARCHY.xlsx");
    }
}
