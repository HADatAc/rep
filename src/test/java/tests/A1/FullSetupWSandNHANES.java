package tests.A1;

import org.junit.jupiter.api.Test;
import org.junit.platform.engine.DiscoverySelector;
import org.junit.platform.engine.discovery.DiscoverySelectors;
import org.junit.platform.launcher.Launcher;
import org.junit.platform.launcher.TestExecutionListener;
import org.junit.platform.launcher.core.LauncherDiscoveryRequestBuilder;
import org.junit.platform.launcher.core.LauncherFactory;
import org.junit.platform.launcher.listeners.SummaryGeneratingListener;

import tests.config.BEViaFEStatusSimpleCheck;
import tests.config.BEViaFEStatusTest;
import tests.config.FusekiConnectionTest;
import tests.repository.ConfigValidationTest;
import tests.utils.FullDeleteTest;
import tests.utils.FullIngestNHANESTestDRAFT;
import tests.utils.FullIngestWSTestDRAFT;
import tests.utils.FullUploadNHANESTestALL;
import tests.utils.FullUploadWS;

public class FullSetupWSandNHANES {
    private final Launcher launcher = LauncherFactory.create();

    @Test
    void runOnlyIngestsForCurrentMode() throws InterruptedException {
        try {
            // Setup of rep configuration
            /*
            runTestClassAndAbortOnFailure(RepositoryFormAutomationTest.class);
            Thread.sleep(5000);

            //Admin Status and Data conf permission
            runTestClassAndAbortOnFailure(AdminAuto.class);
            Thread.sleep(5000);
            */

            // Run Fuseki connection test
            runTestClassAndAbortOnFailure(FusekiConnectionTest.class);
            Thread.sleep(5000);

            // Run BE via FE status simple check
            runTestClassAndAbortOnFailure(BEViaFEStatusSimpleCheck.class);
            Thread.sleep(5000);


            /*
            runTestClassAndAbortOnFailure(BEViaFEStatusTest.class);
            Thread.sleep(5000);
            */

            // Run repository configuration validation
            runTestClassAndAbortOnFailure(ConfigValidationTest.class);
            Thread.sleep(5000);

            // Upload WS files
            runTestClassAndAbortOnFailure(FullUploadWS.class);
            Thread.sleep(5000);

            // Ingest WS files
            runTestClassAndAbortOnFailure(FullIngestWSTestDRAFT.class);
            Thread.sleep(5000);

            // Upload NHANES files
            runTestClassAndAbortOnFailure(FullUploadNHANESTestALL.class);
            Thread.sleep(5000);

            // Ingest NHANES files
            runTestClassAndAbortOnFailure(FullIngestNHANESTestDRAFT.class);
            Thread.sleep(5000);

            // Run regression tests
            runTestClassAndAbortOnFailure(FullRegressionTest.class);
            Thread.sleep(5000);

            /*
            //AttachPDFINST
            runTestClassAndAbortOnFailure(AttachPDFINST.class);
            Thread.sleep(5000);
            */

        } catch (Exception e) {
            System.err.println("⚠️ Test sequence aborted due to failure: " + e.getMessage());
        } finally {
            // Ensure cleanup always runs at the end
            try {
                System.out.println("===> Running cleanup: FullDeleteTest");
                runTestClassAndAbortOnFailure(FullDeleteTest.class);
                Thread.sleep(5000);
            } catch (Exception cleanupError) {
                System.err.println("⚠️ Cleanup (FullDeleteTest) also failed: " + cleanupError.getMessage());
            }
        }
    }

    private void runTestClassAndAbortOnFailure(Class<?> testClass) {
        System.out.println("===> Running: " + testClass.getSimpleName());

        SummaryGeneratingListener listener = new SummaryGeneratingListener();
        launcher.execute(
                LauncherDiscoveryRequestBuilder.request()
                        .selectors(new DiscoverySelector[]{DiscoverySelectors.selectClass(testClass)})
                        .build(),
                listener
        );

        long failures = listener.getSummary().getFailures().size();
        if (failures > 0) {
            throw new RuntimeException("Test failed in " + testClass.getSimpleName());
        }
    }
}
