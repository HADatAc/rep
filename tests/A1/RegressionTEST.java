package tests.A1;

import org.junit.jupiter.api.Test;
import org.junit.platform.engine.discovery.DiscoverySelectors;
import org.junit.platform.launcher.*;
import org.junit.platform.launcher.core.LauncherDiscoveryRequestBuilder;
import org.junit.platform.launcher.core.LauncherFactory;
import org.junit.platform.launcher.listeners.SummaryGeneratingListener;
import org.junit.platform.launcher.listeners.TestExecutionSummary;

public class RegressionTEST {
    private final Launcher launcher = LauncherFactory.create();
    private final SummaryGeneratingListener listener = new SummaryGeneratingListener();

    @Test
    void runsetupanddeletetests() throws InterruptedException {
       /* runTestClass(RepositoryFormAutomationTest.class);
        Thread.sleep(2000);


        runTestClass(AdminAuto.class);
        Thread.sleep(2000);

        */

        runTestClass(FullUploadTestALL.class);
        Thread.sleep(2000);


        runTestClass(FullIngestTestDRAFT.class);
        Thread.sleep(2000);



      /* runTestClass(FullRegressionTest.class);
        Thread.sleep(2000);

        runTestClass(AttachPDFINST.class);
        Thread.sleep(2000);

       */

        runTestClass(FullDeleteTest.class);
        Thread.sleep(2000);



    }

    private void runTestClass(Class<?> testClass) {
        System.out.println("===> Running: " + testClass.getSimpleName());

        LauncherDiscoveryRequest request = LauncherDiscoveryRequestBuilder.request()
                .selectors(DiscoverySelectors.selectClass(testClass))
                .build();

        launcher.execute(request, listener);

        TestExecutionSummary summary = listener.getSummary();

        System.out.printf("==== Summary for %s ====\n", testClass.getSimpleName());
        System.out.println("Tests found: " + summary.getTestsFoundCount());
        System.out.println("Tests succeeded: " + summary.getTestsSucceededCount());
        System.out.println("Tests failed: " + summary.getTestsFailedCount());
        System.out.println("Tests aborted: " + summary.getTestsAbortedCount());

        summary.getFailures().forEach(failure -> {
            System.out.println("[FAILURE] " + failure.getTestIdentifier().getDisplayName());
            System.out.println("Reason: " + failure.getException().getMessage());
            failure.getException().printStackTrace(System.out);
        });

        System.out.println("======================================");
    }
}