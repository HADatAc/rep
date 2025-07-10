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
    String ip = "18.203.69.17";

    @BeforeEach
    void setup() throws InterruptedException {
        System.setProperty("webdriver.chrome.driver", "/usr/bin/chromedriver");


        ChromeOptions options = new ChromeOptions();
        options.setBinary("/usr/bin/google-chrome");
        options.addArguments(
            "--headless=new",
            "--no-sandbox",
            "--disable-dev-shm-usage",
            "--disable-gpu",
            "--remote-debugging-port=9222",
            "--window-size=1920,1080",
            "--ignore-certificate-errors"
        );
        options.setAcceptInsecureCerts(true);


        driver = new ChromeDriver(options);
        driver.manage().window().maximize();
        wait = new WebDriverWait(driver, Duration.ofSeconds(30));


        driver.get("http://" + ip + "/user/login");

        Thread.sleep(2000);

        // Contorna a tela de aviso SSL, se necessário
        Actions actions = new Actions(driver);
        actions.sendKeys("thisisunsafe").perform();

        Thread.sleep(2000);

        String pageSource = driver.getPageSource();
        if (pageSource.contains("Your connection is not private") || pageSource.contains("NET::ERR_CERT")) {
            throw new RuntimeException("SSL warning page loaded instead of actual app page.");
        }
        //logCurrentPageState(5000);
        // Espera visibilidade e limpa campos antes de preencher
        WebElement userInput = wait.until(ExpectedConditions.visibilityOfElementLocated(By.id("edit-name")));
        userInput.clear();
        userInput.sendKeys("admin");

        WebElement passInput = wait.until(ExpectedConditions.visibilityOfElementLocated(By.id("edit-pass")));
        passInput.clear();
        passInput.sendKeys("admin");

        clickElementRobust(By.id("edit-submit"));

// Espera a URL geral que indica login
        wait.until(ExpectedConditions.urlContains("/user/"));

// Opcional: valida se há erro na página
        if (pageSource.contains("Unrecognized username or password")) {
            throw new RuntimeException("Falha no login: usuário ou senha incorretos.");
        }

// Espera toolbar aparecer
        wait.until(driver -> ((JavascriptExecutor) driver).executeScript(
            "return document.querySelector('#toolbar-item-user') !== null && document.querySelector('#toolbar-item-user').offsetParent !== null;"
        ).equals(true));

        System.out.println("Login OK.");



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
        //logCurrentPageState(500);

        // Preenchimento dos campos obrigatórios com logs
        fillInput("Repository Short Name (ex. \"ChildFIRST\")", "HADATAC");
        fillInput("Repository Full Name (ex. \"ChildFIRST: Focus on Innovation\")", "HADATAC");
        fillInput("Repository URL (ex: http://childfirst.ucla.edu, http://tw.rpi.edu, etc.)", "https://hadatac.org");
        fillInput("Prefix for Base Namespace (ex: ufmg, ucla, rpi, etc.)", "hadatac");
        fillInput("URL for Base Namespace", "https://hadatac.org/ont/hadatac#");
        fillInput("Mime for Base Namespace", "");
        fillInput("Source for Base Namespace", "");
        fillInput("description for the repository that appears in the rep APIs GUI", "HADATAC");
        fillInput("Sagres Base URL", "https://52.214.194.214/");

        String apiip = "34.244.13.53"; // IP da API de testes
        String apiUrl = "http://" + apiip + ":9000";
        fillInput("rep API Base URL", apiUrl);


        //logCurrentPageState(50000);

        String expectedFullName = "HADATAC";
        int maxAttempts = 3;
        int attempts = 0;
        boolean formConfirmed = false;

        while (!formConfirmed && attempts < maxAttempts) {
            attempts++;
            System.out.println("Tentativa de submissão #" + attempts);

            try {
                /*WebElement submitButton = driver.findElement(By.cssSelector("//button[text()='Log in']"));
                System.out.println("Botão de envio encontrado: " + submitButton.isDisplayed());
                wait.until(ExpectedConditions.elementToBeClickable(submitButton));
                System.out.println("Botão de envio está habilitado: " + submitButton.isEnabled());
                if (!submitButton.isEnabled()) {
                    throw new RuntimeException("Botão de envio está desabilitado.");
                }
                 */
                System.out.println("Submetendo formulário...");


                clickElementRobust(By.id("edit-submit"));

                System.out.println("Formulário submetido, esperando resposta...");
                formConfirmed = true;
                /*try {
                    wait.until(ExpectedConditions.or(
                            ExpectedConditions.urlContains("/rep/repo/info"),
                            ExpectedConditions.presenceOfElementLocated(By.cssSelector(".messages--status"))
                    ));
                    System.out.println("Formulário submetido com sucesso ou mensagem de status encontrada.");
                } catch (TimeoutException e) {
                    System.out.println("Timeout esperando resposta.");
                }

                 */
                //logCurrentPageState(500);
                //Thread.sleep(2000);
                //System.out.println("Esperando URL ou mensagem de status...");
                /*String currentUrl = driver.getCurrentUrl();
                System.out.println("URL atual: " + currentUrl);
                Assertions.assertNotNull(currentUrl);
                if (currentUrl.contains("/rep/repo/info")) {
                    System.out.println("Formulário submetido com sucesso!");
                    formConfirmed = true;
                }
                 */
               /* List<WebElement> messages = driver.findElements(By.cssSelector(".messages--error, .messages--warning, .form-item--error-message"));
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


                */
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
        //logCurrentPageState(500);

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

            clickElementRobust(By.id("edit-submit"));


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

                // Pequena pausa para garantir processamento
                Thread.sleep(300);

                System.out.println("Clique robusto finalizado na tentativa " + attempt);
                return; // sucesso

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


}
