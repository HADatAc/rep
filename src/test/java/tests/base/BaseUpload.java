package tests.base;

import java.io.File;
import java.time.Duration;

import org.junit.jupiter.api.AfterAll;
import static org.junit.jupiter.api.Assertions.assertTrue;
import static org.junit.jupiter.api.Assertions.fail;
import org.junit.jupiter.api.BeforeAll;
import org.junit.jupiter.api.TestInstance;
import org.openqa.selenium.By;
import org.openqa.selenium.JavascriptExecutor;
import org.openqa.selenium.StaleElementReferenceException;
import org.openqa.selenium.WebDriver;
import org.openqa.selenium.WebElement;
import org.openqa.selenium.chrome.ChromeDriver;
import org.openqa.selenium.chrome.ChromeOptions;
import org.openqa.selenium.support.ui.ExpectedConditions;
import org.openqa.selenium.support.ui.WebDriverWait;

import static tests.config.EnvConfig.LOGIN_URL;
import static tests.config.EnvConfig.PASSWORD;
import static tests.config.EnvConfig.UPLOAD_URL;
import static tests.config.EnvConfig.USERNAME;

@TestInstance(TestInstance.Lifecycle.PER_CLASS)
public abstract class BaseUpload {

    protected WebDriver driver;
    protected WebDriverWait wait;

    @BeforeAll
    void setup() throws InterruptedException{
        System.setProperty("webdriver.chrome.driver", "/var/data/chromedriver/chromedriver");
        ChromeOptions options = new ChromeOptions();
        options.addArguments("--remote-allow-origins=*");
        options.addArguments("--headless");
        options.addArguments("--no-sandbox");
        options.addArguments("--disable-dev-shm-usage");
        options.addArguments("--disable-gpu");
        options.addArguments("--ignore-certificate-errors");

        driver = new ChromeDriver(options);
        driver.manage().window().maximize();
        wait = new WebDriverWait(driver, Duration.ofSeconds(10));
        System.out.println("Navigating to login page: " + LOGIN_URL);
        driver.get(LOGIN_URL);
        Thread.sleep(3000);
        driver.findElement(By.id("edit-name")).sendKeys(USERNAME);
        Thread.sleep(3000);
        driver.findElement(By.id("edit-pass")).sendKeys(PASSWORD);
        Thread.sleep(3000);
        System.out.println("Credentials entered.");
        // Robust click for login
        clickElementRobust(By.id("edit-submit"));
        System.out.println("Login submitted.");
        Thread.sleep(3000);

        wait.until(ExpectedConditions.visibilityOfElementLocated(
                By.cssSelector("#toolbar-item-user")));
    }

    protected void navigateToUploadPage(String type) {
        String url = UPLOAD_URL + type + "/none/F";
        driver.get(url);
        wait.until(ExpectedConditions.visibilityOfElementLocated(By.tagName("form")));
    }

    protected void fillInputByLabel(String label, String value) {
        WebElement input = driver.findElement(By.xpath("//label[contains(text(),'" + label + "')]/following::input[1]"));
        input.sendKeys(value);
    }

    protected void uploadFile(File file) {
        assertTrue(file.exists(), "File does not exist at given path: " + file.getAbsolutePath());

        try {
            WebElement fileInput = driver.findElement(By.cssSelector("input[name='files[mt_filename]']"));
            ((JavascriptExecutor) driver).executeScript("arguments[0].scrollIntoView(true);", fileInput);
            ((JavascriptExecutor) driver).executeScript("arguments[0].style.display='block'; arguments[0].style.opacity=1;", fileInput);

            fileInput.sendKeys(file.getAbsolutePath());

            ((JavascriptExecutor) driver).executeScript(
                    "arguments[0].dispatchEvent(new Event('change', { bubbles: true }));", fileInput);

            Thread.sleep(2000);
            System.out.println("File uploaded: " + file.getAbsolutePath());

        } catch (Exception e) {
            fail("Failed to upload the file: " + e.getMessage());
        }
    }

    protected void submitFormAndVerifySuccess() {
        try {
            By saveButtonLocator = By.xpath("//button[contains(text(), 'Save')]");
            Thread.sleep(2000); // Ensure button is ready
            clickElementRobust(saveButtonLocator);

            boolean confirmationAppeared = wait.until(driver ->
                    driver.findElements(By.cssSelector(".messages.status, .alert-success")).size() > 0 ||
                            driver.getPageSource().toLowerCase().contains("successfully")
            );

            assertTrue(confirmationAppeared, "No confirmation message found after upload.");
        } catch (Exception e) {
            fail("Failed to upload the file: " + e.getMessage());
        }
    }

    // ===== Robust Click Helpers =====

    protected void clickElementRobust(By locator) {
        int maxAttempts = 5;
        int attempt = 0;

        System.out.println("Robust click started for locator: " + locator);
        while (attempt < maxAttempts) {
            attempt++;
            try {
                WebElement element = wait.until(ExpectedConditions.elementToBeClickable(locator));
                clickElementRobust(element);
                System.out.println("Robust click finished at attempt " + attempt);
                return;
            } catch (StaleElementReferenceException sere) {
                System.out.println("Stale element, retry " + attempt);
            } catch (Exception e) {
                System.out.println("Error at attempt " + attempt + ": " + e.getMessage());
                if (attempt == maxAttempts) {
                    throw new RuntimeException("Failed to click after " + maxAttempts + " attempts", e);
                }
            }
        }
    }

    protected void clickElementRobust(WebElement element) {
        try {
            element.click();
            System.out.println("Standard click succeeded");
        } catch (Exception e) {
            System.out.println("Standard click failed, using JS click: " + e.getMessage());
            ((JavascriptExecutor) driver).executeScript("arguments[0].click();", element);
        }

        try {
            Thread.sleep(300); // Allow page processing
        } catch (InterruptedException ignored) {}
    }

    @AfterAll
    void teardown() {
        if (driver != null) {
            driver.quit();
        }
    }
}
