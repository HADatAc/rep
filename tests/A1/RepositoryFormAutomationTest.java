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
        options.setBinary("/usr/bin/chromium-browser");
        options.addArguments("--headless");
        options.addArguments("--no-sandbox");
        options.addArguments("--disable-dev-shm-usage");
        options.addArguments("--disable-gpu");
        options.setAcceptInsecureCerts(true); // Ignora erros de certificado
        options.addArguments("--ignore-certificate-errors"); // ignora erros de HTTPS

        driver = new ChromeDriver(options);
        driver.manage().window().maximize();
        wait = new WebDriverWait(driver, Duration.ofSeconds(30));

        driver.get("http://" + ip + "/user/login");

        // Aguarda aparecer a página de erro
        Thread.sleep(2000);

        // Digita 'thisisunsafe' para forçar o Chrome a continuar
        Actions actions = new Actions(driver);
        actions.sendKeys("thisisunsafe").perform();

        Thread.sleep(3000); // Espera 3 segundos para o Chrome processar o comando

        String pageSource = driver.getPageSource();
        if (pageSource.contains("Your connection is not private") || pageSource.contains("NET::ERR_CERT")) {
            throw new RuntimeException("SSL warning page loaded instead of actual app page.");
        }

        System.out.println("Page source: " + driver.getPageSource());
        wait.until(ExpectedConditions.or(
                ExpectedConditions.urlContains("/user/login"),
                ExpectedConditions.visibilityOfElementLocated(By.id("edit-name"))
        ));
        wait.until(ExpectedConditions.visibilityOfElementLocated(By.id("edit-name")));
        driver.findElement(By.id("edit-name")).sendKeys("admin");
        driver.findElement(By.id("edit-pass")).sendKeys("admin");
        clickElementRobust(By.xpath("//button[text()='Log in']"));


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
        System.out.println("Page source: " + driver.getPageSource());
        ensureJwtKeyExists();

        wait.until(ExpectedConditions.visibilityOfElementLocated(By.cssSelector("select[name='jwt_secret']"))).click();
        Select jwtDropdown = new Select(driver.findElement(By.cssSelector("select[name='jwt_secret']")));
        jwtDropdown.selectByVisibleText("jwt");

        wait.until(driver -> findInputByLabel("Repository Short Name (ex. \"ChildFIRST\")") != null);
        WebElement checkbox = wait.until(ExpectedConditions.presenceOfElementLocated(By.id("edit-sagres-conf")));
        ((JavascriptExecutor) driver).executeScript("arguments[0].scrollIntoView(true);", checkbox);
        wait.until(ExpectedConditions.elementToBeClickable(checkbox));
        if (!checkbox.isSelected()) {
            ((JavascriptExecutor) driver).executeScript("arguments[0].click();", checkbox);
        }

        // Preenchimento dos campos obrigatórios com logs
        fillInput("Repository Short Name (ex. \"ChildFIRST\")", "PMSR");
        fillInput("Repository Full Name (ex. \"ChildFIRST: Focus on Innovation\")", "Portuguese Medical Social Repository");
        fillInput("Repository URL (ex: http://childfirst.ucla.edu, http://tw.rpi.edu, etc.)", "https://pmsr.net");
        fillInput("Prefix for Base Namespace (ex: ufmg, ucla, rpi, etc.)", "psmr");
        fillInput("URL for Base Namespace", "https://pmsr.net");
        fillInput("Mime for Base Namespace", "text/turtle");
        fillInput("Source for Base Namespace", "hadatac");
        fillInput("description for the repository that appears in the rep APIs GUI", "pmsr123");
        fillInput("Sagres Base URL", "https://52.214.194.214/");

        String localIp = getLocalIpAddress();
        String apiUrl = "http://" + localIp + ":9000";
        fillInput("rep API Base URL", apiUrl);

        String expectedFullName = "Portuguese Medical Social Repository";
        int maxAttempts = 3;
        int attempts = 0;
        boolean formConfirmed = false;

        while (!formConfirmed && attempts < maxAttempts) {
            attempts++;
            System.out.println("Tentativa de submissão #" + attempts);

            try {
                WebElement submitButton = driver.findElement(By.cssSelector("input#edit-submit"));
                System.out.println("Botão de envio encontrado: " + submitButton.isDisplayed());
                wait.until(ExpectedConditions.elementToBeClickable(submitButton));
                System.out.println("Botão de envio está habilitado: " + submitButton.isEnabled());
                if (!submitButton.isEnabled()) {
                    throw new RuntimeException("Botão de envio está desabilitado.");
                }

                clickElementRobust(By.xpath("//button[text()='Log in']"));


                try {
                    wait.until(ExpectedConditions.or(
                            ExpectedConditions.urlContains("/rep/repo/info"),
                            ExpectedConditions.presenceOfElementLocated(By.cssSelector(".messages--status"))
                    ));
                    System.out.println("Formulário submetido com sucesso ou mensagem de status encontrada.");
                } catch (TimeoutException e) {
                    System.out.println("Timeout esperando resposta.");
                }

                Thread.sleep(2000);
                String currentUrl = driver.getCurrentUrl();
                System.out.println("URL atual: " + currentUrl);

                List<WebElement> messages = driver.findElements(By.cssSelector(".messages--error, .messages--warning, .form-item--error-message"));
                for (WebElement msg : messages) {
                    System.out.println("Mensagem: " + msg.getText());
                }

                List<WebElement> fallbackMessages = driver.findElements(By.cssSelector("[data-drupal-messages-fallback] .messages--error"));
                for (WebElement msg : fallbackMessages) {
                    System.out.println("Mensagem (fallback): " + msg.getText());
                }

                if (currentUrl.contains("/rep/repo/info")) {
                    System.out.println("Formulário submetido com sucesso!");
                    formConfirmed = true;
                } else {
                    System.out.println("Recarregando formulário...");

                    driver.get("http://" + ip + "/admin/config/rep");

                    WebElement fullNameField = findInputByLabel("Repository Full Name (ex. \"ChildFIRST: Focus on Innovation\")");
                    if (fullNameField != null && fullNameField.getAttribute("value").trim().isEmpty()) {
                        fillInput("Repository Full Name (ex. \"ChildFIRST: Focus on Innovation\")", expectedFullName);
                    }

                    WebElement jwtSelect = wait.until(ExpectedConditions.presenceOfElementLocated(By.cssSelector("select[name='jwt_secret']")));
                    jwtDropdown = new Select(jwtSelect);
                    jwtDropdown.selectByVisibleText("jwt");
                }

            } catch (Exception e) {
                System.out.println("Erro inesperado: " + e.getMessage());
                e.printStackTrace();
            }
        }

        if (!formConfirmed) {
            throw new RuntimeException("Falha após " + maxAttempts + " tentativas.");
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

            clickElementRobust(By.xpath("//button[text()='Log in']"));


            wait.until(ExpectedConditions.urlContains("/admin/config/system/keys"));
            System.out.println("JWT key created successfully.");

            driver.get("http://" + ip + "/admin/config/rep");
        } else {
            System.out.println("JWT key 'jwt' already exists.");
        }
    }

    private void fillInput(String label, String value) {
        WebElement input = findInputByLabel(label);
        if (input != null) {
            input.clear();
            input.sendKeys(value);
            System.out.printf("Campo \"%s\" preenchido com: %s%n", label, value);
        } else {
            throw new RuntimeException("Campo com label \"" + label + "\" não encontrado.");
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
    private void clickElementRobust(By locator) {
        try {
            WebElement element = wait.until(ExpectedConditions.elementToBeClickable(locator));
            System.out.println("Tentando clicar no elemento: " + element.getTagName());

            ((JavascriptExecutor) driver).executeScript("arguments[0].scrollIntoView({block: 'center'});", element);
            Thread.sleep(500);
            System.out.println("Elemento visível e pronto para clique: " + element.getTagName());
            Boolean isOverlapped = (Boolean) ((JavascriptExecutor) driver).executeScript(
                    "var elem = arguments[0];" +
                            "var rect = elem.getBoundingClientRect();" +
                            "var elFromPoint = document.elementFromPoint(rect.left + rect.width / 2, rect.top + rect.height / 2);" +
                            "return !(elem === elFromPoint || elem.contains(elFromPoint));", element
            );

            if (isOverlapped) {
                System.out.println("Elemento sobreposto, tentando clique via JavaScript.");
            }
            System.out.println("Elemento está visível e pronto para clique: " + element.getTagName());
            try {
                element.click();
                System.out.println("Clique WebDriver realizado com sucesso.");
            } catch (ElementClickInterceptedException e) {
                System.out.println("Clique WebDriver falhou. Usando JavaScript.");
                ((JavascriptExecutor) driver).executeScript("arguments[0].click();", element);
                System.out.println("Clique via JavaScript realizado com sucesso.");
            }
            System.out.println("Clique no elemento realizado com sucesso: " + element.getTagName());
            ((JavascriptExecutor) driver).executeScript(
                    "arguments[0].dispatchEvent(new Event('change', { bubbles: true }));", element
            );
            System.out.println("Evento 'change' disparado no elemento: " + element.getTagName());
        } catch (StaleElementReferenceException e) {
            System.out.println("Elemento obsoleto (stale): " + e.getMessage());
            throw new RuntimeException("Elemento ficou obsoleto (stale): " + e.getMessage(), e);
        } catch (Exception e) {
            System.out.println("Falha ao clicar no elemento: " + e.getMessage());
            throw new RuntimeException("Falha ao clicar no elemento: " + e.getMessage(), e);
        }
        System.out.println("Clique robusto realizado com sucesso.");
    }


}
