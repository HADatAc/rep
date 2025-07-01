package tests.A1;

import org.junit.jupiter.api.*;
import org.openqa.selenium.*;
import org.openqa.selenium.chrome.ChromeDriver;
import org.openqa.selenium.chrome.ChromeOptions;
import org.openqa.selenium.support.ui.*;
import java.net.InetAddress;
import java.net.UnknownHostException;
import java.time.Duration;
import java.util.List;

public class RepositoryFormAutomationTest {

    WebDriver driver;
    WebDriverWait wait;
    String ip = "54.75.120.47";

    @BeforeEach
    void setup() {
        ChromeOptions options = new ChromeOptions();
        options.setBinary("/usr/bin/chromium-browser");
        options.addArguments("--headless");
        options.addArguments("--no-sandbox");
        options.addArguments("--disable-dev-shm-usage");
        options.addArguments("--disable-gpu");

        driver = new ChromeDriver(options);
        driver.manage().window().maximize();
        wait = new WebDriverWait(driver, Duration.ofSeconds(30));

        driver.get("http://" + ip + "/user/login");

        // Wait for the username field to appear before typing
        wait.until(ExpectedConditions.visibilityOfElementLocated(By.id("edit-name")));

        driver.findElement(By.id("edit-name")).sendKeys("admin");
        driver.findElement(By.id("edit-pass")).sendKeys("admin");
        clickElementSafely(driver.findElement(By.id("edit-submit")));

        // Wait for an element that appears only after login
        wait.until(ExpectedConditions.visibilityOfElementLocated(By.cssSelector("#toolbar-item-user")));
    }

    @AfterEach
    void teardown() {
        if (driver != null) {
            driver.quit();
        }
    }

    @Test
    void testFillRepositoryForm() throws InterruptedException {
        driver.get("http://" + ip + "/admin/config/rep");

        ensureJwtKeyExists();

        Select jwtDropdown = new Select(driver.findElement(By.cssSelector("select[name='jwt_secret']")));
        jwtDropdown.selectByVisibleText("jwt");

        waitUntilInputVisible("Repository Short Name (ex. \"ChildFIRST\")");

        WebElement checkbox = wait.until(ExpectedConditions.presenceOfElementLocated(By.id("edit-sagres-conf")));
        scrollIntoView(checkbox);
        waitABit(500);

        if (!checkbox.isSelected()) {
            clickElementSafely(checkbox);
        }

        fillInput("Repository Short Name (ex. \"ChildFIRST\")", "PMSR");
        fillInput("Repository Full Name (ex. \"ChildFIRST: Focus on Innovation\")", "Portuguese Medical Social Repository");
        fillInput("Repository URL (ex: http://childfirst.ucla.edu, http://tw.rpi.edu, etc.)", "https://pmsr.net");
        fillInput("Prefix for Base Namespace (ex: ufmg, ucla, rpi, etc.)", "psmr");
        fillInput("URL for Base Namespace", "https://pmsr.net");
        fillInput("Mime for Base Namespace", "text/turtle");
        fillInput("Source for Base Namespace", "hadatac");
        fillInput("description for the repository that appears in the rep APIs GUI", "pmsr123");
        fillInput("Sagres Base URL", "https://52.214.194.214/");

        //String localIp = getLocalIpAddress();

        String apiUrl = "http://" + ip + ":9000";
        fillInput("rep API Base URL", apiUrl);

        String expectedFullName = "Portuguese Medical Social Repository";
        boolean formConfirmed = false;

        while (!formConfirmed) {
            WebElement saveBtn = driver.findElement(By.cssSelector("input#edit-submit"));
            clickElementSafely(saveBtn);

            wait.until(ExpectedConditions.or(
                    ExpectedConditions.urlContains("/rep/repo/info"),
                    ExpectedConditions.presenceOfElementLocated(By.cssSelector(".messages--status"))
            ));

            String currentUrl = driver.getCurrentUrl();
            if (currentUrl.contains("/rep/repo/info")) {
                System.out.println("Final page detected: " + currentUrl);
                formConfirmed = true;
            } else {
                driver.get("http://" + ip + "/admin/config/rep");

                WebElement fullNameField = findInputByLabel("Repository Full Name (ex. \"ChildFIRST: Focus on Innovation\")");
                if (fullNameField != null && fullNameField.getAttribute("value").trim().isEmpty()) {
                    System.out.println("'Repository Full Name' field was empty after saving. Refilling and retrying...");
                    fillInput("Repository Full Name (ex. \"ChildFIRST: Focus on Innovation\")", expectedFullName);
                }

                WebElement jwtSelect = wait.until(ExpectedConditions.presenceOfElementLocated(
                        By.cssSelector("select[name='jwt_secret']")));
                new Select(jwtSelect).selectByVisibleText("jwt");
            }
        }
    }

    private void ensureJwtKeyExists() {
        WebElement jwtSelect = wait.until(ExpectedConditions.presenceOfElementLocated(
                By.cssSelector("select[name='jwt_secret']")));

        Select jwtDropdown = new Select(jwtSelect);
        boolean jwtExists = jwtDropdown.getOptions().stream()
                .anyMatch(option -> option.getText().trim().equals("jwt"));

        if (!jwtExists) {
            System.out.println("JWT key 'jwt' not found, creating...");

            driver.get("http://" + ip + "/admin/config/system/keys/add");

            wait.until(ExpectedConditions.urlContains("/admin/config/system/keys/add"));

            WebElement labelField = wait.until(ExpectedConditions.visibilityOfElementLocated(By.id("edit-label")));
            labelField.sendKeys("jwt");

            WebElement descField = wait.until(ExpectedConditions.visibilityOfElementLocated(By.id("edit-description")));
            descField.sendKeys("jwt");

            new Select(wait.until(ExpectedConditions.visibilityOfElementLocated(By.id("edit-key-type"))))
                    .selectByValue("authentication");

            new Select(wait.until(ExpectedConditions.visibilityOfElementLocated(By.id("edit-key-provider"))))
                    .selectByVisibleText("Configuration");

            WebElement valueField = wait.until(ExpectedConditions.visibilityOfElementLocated(
                    By.id("edit-key-input-settings-key-value")));
            valueField.clear();
            valueField.sendKeys("qwertyuiopasdfghjklzxcvbnm123456");

            WebElement submitButton = wait.until(ExpectedConditions.elementToBeClickable(By.id("edit-submit")));
            clickElementSafely(submitButton);

            wait.until(ExpectedConditions.urlContains("/admin/config/system/keys"));
            System.out.println("JWT key created successfully.");

            driver.get("http://" + ip + "/admin/config/rep");
        } else {
            System.out.println("JWT key 'jwt' already exists.");
        }
    }

    private void fillInput(String labelText, String value) {
        WebElement input = findInputByLabel(labelText);
        if (input != null) {
            input.clear();
            input.sendKeys(value);
        } else {
            throw new RuntimeException("Field with label '" + labelText + "' not found.");
        }
    }

    private WebElement findInputByLabel(String labelText) {
        List<WebElement> labels = driver.findElements(By.tagName("label"));
        for (WebElement label : labels) {
            if (label.getText().trim().equals(labelText)) {
                String forAttr = label.getAttribute("for");
                if (forAttr != null && !forAttr.isEmpty()) {
                    try {
                        return driver.findElement(By.id(forAttr));
                    } catch (NoSuchElementException ignored) {}
                }
            }
        }
        return null;
    }

    private String getLocalIpAddress() {
        try {
            String ipAddr = InetAddress.getLocalHost().getHostAddress();
            System.out.printf("Local IP detected: %s%n", ipAddr);
            return ipAddr;
        } catch (UnknownHostException e) {
            System.out.println("Could not retrieve local IP. Using localhost as fallback.");
            return "127.0.0.1";
        }
    }

    private void scrollIntoView(WebElement element) {
        ((JavascriptExecutor) driver).executeScript("arguments[0].scrollIntoView(true);", element);
    }

    private void waitUntilInputVisible(String labelText) {
        wait.until(driver -> findInputByLabel(labelText) != null && findInputByLabel(labelText).isDisplayed());
    }

    private void waitABit(long millis) {
        try {
            Thread.sleep(millis);
        } catch (InterruptedException ignored) {}
    }

    /**
     * Tenta clicar no elemento usando Selenium padrão.
     * Se o clique for interceptado, usa Javascript para clicar.
     */
    private void clickElementSafely(WebElement element) {
        try {
            wait.until(ExpectedConditions.elementToBeClickable(element));
            element.click();
        } catch (ElementClickInterceptedException e) {
            System.out.println("Click intercepted, trying JS click...");
            ((JavascriptExecutor) driver).executeScript("arguments[0].click();", element);
        }
    }
}
