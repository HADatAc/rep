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

        wait.until(ExpectedConditions.visibilityOfElementLocated(By.id("edit-name")));
        driver.findElement(By.id("edit-name")).sendKeys("admin");
        driver.findElement(By.id("edit-pass")).sendKeys("admin");
        clickElementRobust(driver.findElement(By.id("edit-submit")));

        wait.until(ExpectedConditions.visibilityOfElementLocated(By.cssSelector("#toolbar-item-user")));
    }

    @AfterEach
    void teardown() {
        if (driver != null) {
            driver.quit();
        }
    }

    @Test
    void testFillRepositoryForm() {
        driver.get("http://" + ip + "/admin/config/rep");

        ensureJwtKeyExists();

        Select jwtDropdown = new Select(driver.findElement(By.cssSelector("select[name='jwt_secret']")));
        jwtDropdown.selectByVisibleText("jwt");

        String localIp = getLocalIpAddress();
        String apiUrl = "http://" + localIp + ":9000";
        WebElement input = findInputByLabel("rep API Base URL");
        if (input != null) {
            input.clear();
            input.sendKeys(apiUrl);
        } else {
            System.out.println("Campo 'rep API Base URL' não encontrado!");
        }

        String expectedFullName = "Portuguese Medical Social Repository";

        int maxAttempts = 3;
        int attempts = 0;
        boolean formConfirmed = false;

        while (!formConfirmed && attempts < maxAttempts) {
            attempts++;
            System.out.println("Tentando submeter o formulário, tentativa #" + attempts);
            clickElementRobust(driver.findElement(By.cssSelector("input#edit-submit")));

            try {
                wait.until(ExpectedConditions.or(
                        ExpectedConditions.urlContains("/rep/repo/info"),
                        ExpectedConditions.presenceOfElementLocated(By.cssSelector(".messages--status"))
                ));
            } catch (TimeoutException e) {
                System.out.println("Timeout esperando resposta após submit.");
            }

            String currentUrl = driver.getCurrentUrl();
            System.out.println("URL atual após submit: " + currentUrl);

            if (currentUrl.contains("/rep/repo/info")) {
                System.out.println("Final page detectada: " + currentUrl);
                formConfirmed = true;
            } else {
                System.out.println("Não avançou para página final, recarregando e preenchendo...");
                driver.get("http://" + ip + "/admin/config/rep");

                WebElement fullNameField = findInputByLabel("Repository Full Name (ex. \"ChildFIRST: Focus on Innovation\")");
                System.out.println("Valor do campo Repository Full Name: " + (fullNameField != null ? fullNameField.getAttribute("value") : "campo não encontrado"));
                if (fullNameField != null && fullNameField.getAttribute("value").trim().isEmpty()) {
                    fillInput("Repository Full Name (ex. \"ChildFIRST: Focus on Innovation\")", expectedFullName);
                }

                WebElement jwtSelect = wait.until(ExpectedConditions.presenceOfElementLocated(
                        By.cssSelector("select[name='jwt_secret']")));
                jwtDropdown = new Select(jwtSelect);
                jwtDropdown.selectByVisibleText("jwt");
            }
        }

        if (!formConfirmed) {
            throw new RuntimeException("Falha ao submeter o formulário após " + maxAttempts + " tentativas.");
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

            wait.until(ExpectedConditions.visibilityOfElementLocated(By.id("edit-label"))).sendKeys("jwt");
            driver.findElement(By.id("edit-description")).sendKeys("jwt");

            new Select(driver.findElement(By.id("edit-key-type"))).selectByValue("authentication");
            new Select(driver.findElement(By.id("edit-key-provider"))).selectByVisibleText("Configuration");

            WebElement valueField = wait.until(ExpectedConditions.presenceOfElementLocated(
                    By.id("edit-key-input-settings-key-value")));

            valueField.clear();
            valueField.sendKeys("qwertyuiopasdfghjklzxcvbnm123456");

            clickElementRobust(driver.findElement(By.id("edit-submit")));

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

    private void waitUntilInputVisible(String labelText) {
        wait.until(driver -> findInputByLabel(labelText) != null && findInputByLabel(labelText).isDisplayed());
    }

    private void waitABit(long millis) {
        try {
            Thread.sleep(millis);
        } catch (InterruptedException ignored) {}
    }

    /**
     * Clique robusto: espera, scrolla até o elemento, e usa JavaScript para clicar.
     */
    private void clickElementRobust(WebElement element) {
        try {
            wait.until(ExpectedConditions.elementToBeClickable(element));
            ((JavascriptExecutor) driver).executeScript("arguments[0].scrollIntoView(true);", element);
            Thread.sleep(500); // pequena pausa para garantir visibilidade
            ((JavascriptExecutor) driver).executeScript("arguments[0].click();", element);
        } catch (Exception e) {
            throw new RuntimeException("Falha ao clicar no elemento: " + e.getMessage(), e);
        }
    }
}
