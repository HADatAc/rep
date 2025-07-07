package tests.A1;

import org.junit.jupiter.api.*;
import org.openqa.selenium.*;
import org.openqa.selenium.chrome.ChromeDriver;
import org.openqa.selenium.chrome.ChromeOptions;
import org.openqa.selenium.interactions.Actions;
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
    void setup() throws InterruptedException {
        ChromeOptions options = new ChromeOptions();
        options.addArguments("--headless", "--no-sandbox", "--disable-dev-shm-usage", "--disable-gpu", "--ignore-certificate-errors");
        options.setAcceptInsecureCerts(true);

        driver = new ChromeDriver(options);
        driver.manage().window().maximize();
        wait = new WebDriverWait(driver, Duration.ofSeconds(30));
        System.out.println("Starting driver with IP: " + ip);

        driver.get("http://" + ip + "/user/login");
        Thread.sleep(2000);

        // Contorna SSL
        new Actions(driver).sendKeys("thisisunsafe").perform();
        Thread.sleep(2000);

        if (driver.getPageSource().contains("Your connection is not private")) {
            throw new RuntimeException("SSL warning page loaded instead of actual app page.");
        }

        Thread.sleep(2000);
        System.out.println("Starting login process...");
        driver.findElement(By.id("edit-name")).sendKeys("admin");
        driver.findElement(By.id("edit-pass")).sendKeys("admin");
        clickElementRobust(By.id("edit-submit"));
        System.out.println("Login process completed.");
        Thread.sleep(3000);
    }

    @AfterEach
    void teardown() {
        if (driver != null) driver.quit();
    }

    @Test
    void testFillRepositoryForm() throws InterruptedException {
        driver.get("http://" + ip + "/admin/config/rep");
        System.out.println("Navigating to repository configuration page...");
        Thread.sleep(3000);
        System.out.println("Current URL: " + driver.getCurrentUrl());
        ensureJwtKeyExists();
        Thread.sleep(3000);
        System.out.println("Parando aqui");
        WebElement jwtSelect = driver.findElement(By.id("edit-jwt-secret"));;
        clickElementRobust(By.id("edit-jwt-secret"));
        new Select(jwtSelect).selectByVisibleText("jwt");
        ;

        Thread.sleep(1000);
        WebElement checkbox = driver.findElement(By.id("edit-sagres-conf"));
        ((JavascriptExecutor) driver).executeScript("arguments[0].scrollIntoView(true);", checkbox);
        Thread.sleep(1000);

        if (!checkbox.isSelected()) {
            ((JavascriptExecutor) driver).executeScript("arguments[0].click();", checkbox);
        }

        fillInput("Repository Short Name (ex. \"ChildFIRST\")", "PMSR");
        fillInput("Repository Full Name (ex. \"ChildFIRST: Focus on Innovation\")", "Portuguese Medical Social Repository");
        fillInput("Repository URL (ex: http://childfirst.ucla.edu, http://tw.rpi.edu, etc.)", "https://pmsr.net");
        fillInput("Prefix for Base Namespace (ex: ufmg, ucla, rpi, etc.)", "pmsr");
        fillInput("URL for Base Namespace", "https://pmsr.net");
        fillInput("Mime for Base Namespace", "text/turtle");
        fillInput("Source for Base Namespace", "hadatac");
        fillInput("description for the repository that appears in the rep APIs GUI", "pmsr123");
        fillInput("Sagres Base URL", "https://52.214.194.214/");
        fillInput("rep API Base URL", "http://54.154.41.233:9000");

        Thread.sleep(1000);

        int maxAttempts = 3;
        boolean formConfirmed = false;
        for (int i = 0; i < maxAttempts && !formConfirmed; i++) {
            System.out.println("Tentativa de submissão #" + (i + 1));
            try {
                clickElementRobust(By.id("edit-submit"));
                Thread.sleep(3000);
                formConfirmed = true;
            } catch (Exception e) {
                System.out.println("Erro ao submeter: " + e.getMessage());
            }
        }

        if (!formConfirmed) {
            throw new RuntimeException("Falha após " + maxAttempts + " tentativas.");
        }
    }

    private void ensureJwtKeyExists() throws InterruptedException {
        System.out.println("Verifying if JWT key 'jwt' exists...");
        System.out.println("Current URL: " + driver.getCurrentUrl());
        WebDriverWait wait = new WebDriverWait(driver, Duration.ofSeconds(10));

        WebElement jwtDropdown = null;
        System.out.println("Current URL: " + driver.getCurrentUrl());
        try {
            Thread.sleep(2000);
            System.out.println("Current URL: " + driver.getCurrentUrl());
            jwtDropdown = wait.until(ExpectedConditions.presenceOfElementLocated(By.id("edit-jwt-secret")));
        } catch (TimeoutException e) {
            System.out.println("Dropdown JWT não encontrado, recarregando página...");
            System.out.println("Current URL: " + driver.getCurrentUrl());
            driver.navigate().refresh();
            Thread.sleep(2000);
            try {
                jwtDropdown = wait.until(ExpectedConditions.presenceOfElementLocated(By.id("edit-jwt-secret")));
            } catch (TimeoutException ex) {
                throw new RuntimeException("Dropdown JWT ainda não encontrado após reload.");
            }
        }

        Select select = new Select(jwtDropdown);
        boolean jwtExists = select.getOptions().stream()
                .anyMatch(option -> option.getText().trim().equals("jwt"));

        if (!jwtExists) {
            System.out.println("JWT key 'jwt' not found, creating...");
            driver.get("http://" + ip + "/admin/config/system/keys/add");
            Thread.sleep(2000);

            driver.findElement(By.id("edit-label")).sendKeys("jwt");
            driver.findElement(By.id("edit-description")).sendKeys("jwt");

            new Select(driver.findElement(By.id("edit-key-type"))).selectByValue("authentication");
            new Select(driver.findElement(By.id("edit-key-provider"))).selectByVisibleText("Configuration");

            WebElement valueField = driver.findElement(By.id("edit-key-input-settings-key-value"));
            valueField.clear();
            valueField.sendKeys("qwertyuiopasdfghjklzxcvbnm123456");

            clickElementRobust(By.id("edit-submit"));
            Thread.sleep(2000);

            System.out.println("JWT key created successfully.");
            driver.get("http://" + ip + "/admin/config/rep");
            Thread.sleep(2000);
        } else {
            System.out.println("JWT key 'jwt' already exists.");
        }
    }



    private void fillInput(String label, String value) throws InterruptedException {
        WebElement input = findInputByLabel(label);
        if (input != null) {
            input.clear();
            input.sendKeys(value);
            System.out.printf("Campo \"%s\" preenchido com: %s%n", label, value);
        } else {
            throw new RuntimeException("Campo com label \"" + label + "\" não encontrado.");
        }
        Thread.sleep(500);
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

    private void clickElementRobust(By locator) {
        int maxAttempts = 5;
        for (int attempt = 1; attempt <= maxAttempts; attempt++) {
            try {
                WebElement element = driver.findElement(locator);
                ((JavascriptExecutor) driver).executeScript("arguments[0].scrollIntoView(true);", element);
                Thread.sleep(200);
                try {
                    element.click();
                } catch (Exception e) {
                    ((JavascriptExecutor) driver).executeScript("arguments[0].click();", element);
                }
                Thread.sleep(300);
                System.out.println("Clique robusto finalizado na tentativa " + attempt);
                return;
            } catch (Exception e) {
                System.out.println("Tentativa " + attempt + " falhou: " + e.getMessage());
                if (attempt == maxAttempts) throw new RuntimeException("Erro ao clicar em elemento: " + locator, e);
            }
        }
    }
}
