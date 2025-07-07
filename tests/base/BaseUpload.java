package tests.base;

import org.junit.jupiter.api.*;
import org.openqa.selenium.*;
import org.openqa.selenium.chrome.ChromeDriver;
import org.openqa.selenium.chrome.ChromeOptions;
import org.openqa.selenium.interactions.Actions;
import org.openqa.selenium.support.ui.*;

import java.io.File;
import java.time.Duration;
import java.util.List;

import static org.junit.jupiter.api.Assertions.*;

@TestInstance(TestInstance.Lifecycle.PER_CLASS)
public abstract class BaseUpload {

    protected WebDriver driver;
    protected WebDriverWait wait;
    String ip = "54.75.120.47";
    //String ip = "localhost";

    @BeforeAll
    void setup() throws InterruptedException {
        ChromeOptions options = new ChromeOptions();

        options.setBinary("/var/data/chromium-browser");
        options.addArguments("--headless");
        options.addArguments("--no-sandbox");
        options.addArguments("--disable-dev-shm-usage");
        options.addArguments("--disable-gpu");
        options.setAcceptInsecureCerts(true);
        options.addArguments("--ignore-certificate-errors");

        driver = new ChromeDriver(options);
        driver.manage().window().maximize();
        wait = new WebDriverWait(driver, Duration.ofSeconds(30));

        driver.get("http://" + ip + "/user/login");

        Thread.sleep(2000);

        Actions actions = new Actions(driver);
        actions.sendKeys("thisisunsafe").perform();

        Thread.sleep(2000);
        //logCurrentPageState(1000);
        wait.until(ExpectedConditions.visibilityOfElementLocated(By.id("edit-name")));
        wait.until(ExpectedConditions.elementToBeClickable(By.id("edit-submit")));

        driver.findElement(By.id("edit-name")).sendKeys("admin");
        driver.findElement(By.id("edit-pass")).sendKeys("admin");

        clickElementRobust(By.id("edit-submit"));

        wait.until(ExpectedConditions.visibilityOfElementLocated(By.cssSelector("#toolbar-item-user")));
    }

    protected void navigateToUploadPage(String type) {
        String url = "http://"+ip+"/rep/manage/addmt/" + type + "/none/F";
        driver.get(url);

        wait.until(ExpectedConditions.visibilityOfElementLocated(By.tagName("form")));
        System.out.println("Navigated to upload page for type: " + type);
    }

    protected void fillInputByLabel(String label, String value) {
        WebElement input = driver.findElement(By.xpath("//label[contains(text(),'" + label + "')]/following::input[1]"));
        input.sendKeys(value);
        System.out.println("Filled input with label '" + label + "' with value: " + value);
    }

    protected void uploadFile(File file) {
        assertTrue(file.exists(), "File does not exist at given path: " + file.getAbsolutePath());
        System.out.println("Uploading file: " + file.getAbsolutePath());

        try {
            WebElement fileInput = driver.findElement(By.cssSelector("input[name='files[mt_filename]']"));
            ((JavascriptExecutor) driver).executeScript("arguments[0].scrollIntoView(true);", fileInput);
            ((JavascriptExecutor) driver).executeScript("arguments[0].style.display='block'; arguments[0].style.opacity=1;", fileInput);

            fileInput.sendKeys(file.getAbsolutePath());

            ((JavascriptExecutor) driver).executeScript(
                    "arguments[0].dispatchEvent(new Event('change', { bubbles: true }));", fileInput);

            Thread.sleep(1000); // leve espera para garantir envio
            System.out.println("File uploaded: " + file.getAbsolutePath());

        } catch (Exception e) {
            fail("Failed to upload the file: " + e.getMessage());
        }

      /*  // 🔍 Verificar se o arquivo está visível na listagem
        try {
            String fileName = file.getName();
            String type = inferTypeFromFileName(fileName); // Infer type based on file name
            System.out.println("Verifying upload for file: " + fileName + " of type: " + type);
            driver.get("http://" + ip + "/rep/select/mt/" + type + "/table/1/9/none");
            System.out.println("Waiting for the upload listing page to load...");
            Thread.sleep(2000);
            //wait.until(ExpectedConditions.visibilityOfElementLocated(By.tagName("table")));

            List<WebElement> rows = driver.findElements(By.xpath("//table//tbody//tr"));
            System.out.println("Found " + rows.size() + " rows in the listing table.");
            boolean fileFound = false;

            for (WebElement row : rows) {
                List<WebElement> cells = row.findElements(By.tagName("td"));
                if (cells.size() >= 3) {
                    String name = cells.get(2).getText().trim();
                    if (name.equals(fileName)) {
                        fileFound = true;
                        break;
                    }
                }
            }

            assertTrue(fileFound, "Uploaded file '" + fileName + "' not found in listing page.");
            System.out.println("Upload verified: file '" + fileName + "' found in the listing.");

        } catch (Exception e) {
            fail("Error verifying uploaded file: " + e.getMessage());
        }

       */
    }
    private String inferTypeFromFileName(String fileName) {
        fileName = fileName.toLowerCase();
        if (fileName.contains("ins")) return "INS";
        if (fileName.contains("dp2")) return "DP2";
        if (fileName.contains("dsg")) return "DSG";
        if (fileName.contains("sdd")) return "SDD";
        if (fileName.contains("str")) return "STR";
        return "INS"; // padrão caso não identifique
    }



    protected void submitFormAndVerifySuccess() {
        By saveButtonLocator = By.id("edit-save-submit");
        clickElementRobust(saveButtonLocator);
        System.out.println("Form submitted, waiting for confirmation...");
        boolean confirmationAppeared = wait.until(driver ->
                driver.findElements(By.cssSelector(".messages.status, .alert-success")).size() > 0 ||
                        driver.getPageSource().toLowerCase().contains("successfully")
        );

        assertTrue(confirmationAppeared, "No confirmation message found after upload.");


        System.out.println("Form submitted successfully and confirmation message appeared.");
    }



    protected String extractUriFromSDD() {
        driver.get("http://"+ip+"/sem/select/semanticdatadictionary/1/9");
        wait.until(ExpectedConditions.visibilityOfElementLocated(By.id("edit-element-table")));
        WebElement checkbox = driver.findElement(By.cssSelector("input.form-checkbox.form-check-input"));
        String uri = checkbox.getAttribute("value");
        System.out.println("URI extracted: " + uri);
        return uri;
    }

    protected void clickElementRobust(By locator) {
        int maxAttempts = 5;
        int attempt = 0;

        System.out.println("Clique robusto iniciado para o elemento: " + locator);
        while (attempt < maxAttempts) {
            attempt++;
            try {
                WebElement element = wait.until(ExpectedConditions.elementToBeClickable(locator));

                try {
                    element.click();
                    System.out.println("Clique padrão realizado na tentativa " + attempt);
                } catch (Exception e) {
                    System.out.println("Clique padrão falhou, tentando clique via JS na tentativa " + attempt);
                    ((JavascriptExecutor) driver).executeScript("arguments[0].click();", element);
                }

                Thread.sleep(300);
                System.out.println("Clique robusto finalizado na tentativa " + attempt);
                return;

            } catch (StaleElementReferenceException sere) {
                System.out.println("Elemento stale, retry " + attempt);
            } catch (Exception e) {
                System.out.println("Erro na tentativa " + attempt + ": " + e.getMessage());
                if (attempt == maxAttempts) {
                    throw new RuntimeException("Falha ao clicar após " + maxAttempts + " tentativas", e);
                }
            }
        }
    }
    private void logCurrentPageState(int snippetLength) {
        String currentUrl = driver.getCurrentUrl();
        System.out.println("========== Current Page State ==========");
        System.out.println("URL atual: " + currentUrl);

        String pageSource = driver.getPageSource();
        if (pageSource.length() > snippetLength) {
            pageSource = pageSource.substring(0, snippetLength) + "...";
        }
        System.out.println("Page source snippet: " + pageSource);
        System.out.println("========================================");
    }
    @AfterAll
    void teardown() {
        if (driver != null) {
            driver.quit();
        }
    }
}
