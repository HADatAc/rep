package tests.base;

import org.junit.jupiter.api.*;
import org.openqa.selenium.*;
import org.openqa.selenium.NoSuchElementException;
import org.openqa.selenium.chrome.ChromeDriver;
import org.openqa.selenium.chrome.ChromeOptions;
import org.openqa.selenium.interactions.Actions;
import org.openqa.selenium.support.ui.*;

import java.time.Duration;
import java.util.*;

import static org.junit.jupiter.api.Assertions.*;

@TestInstance(TestInstance.Lifecycle.PER_CLASS)
public abstract class BaseIngest {

    protected WebDriver driver;
    protected WebDriverWait wait;
    public static String ingestMode = "current"; // default
    String buttonName = "ingest_mt_" + ingestMode;
    protected final Map<String, Boolean> selectedRows = new HashMap<>();
    protected static final int MAX_ATTEMPTS = 10;
    protected static final int WAIT_INTERVAL_MS = 30000;
    String ip = "18.203.69.17";
    //String ip = "localhost";
    @BeforeAll
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

    protected void ingestFile(String type) throws InterruptedException {
        driver.get("http://"+ip+"/rep/select/mt/" + type + "/table/1/9/none");
        Thread.sleep(2000); // Wait for UI to update
        System.out.println("Ingesting files of type: " + type);
        try {
            wait.until(ExpectedConditions.visibilityOfElementLocated(By.tagName("table")));
        } catch (TimeoutException e) {
            fail("Table for type '" + type + "' not found.");
        }

        List<WebElement> rows = driver.findElements(By.xpath("//table//tbody//tr"));
        int selectedCount = 0;
        System.out.println("Total table rows found: " + rows.size());

        for (WebElement row : rows) {
            List<WebElement> cells = row.findElements(By.tagName("td"));
            if (cells.size() >= 5) {
                String status = cells.get(4).getText().trim();
                String rowKey = cells.get(1).getText().trim(); // unique name or ID

                if ("UNPROCESSED".equalsIgnoreCase(status)) {
                    try {
                        // Criar o locator do checkbox para passar no clickElementRobust
                        By checkboxLocator = By.cssSelector("tbody tr:nth-child(" + (rows.indexOf(row) + 1) + ") td:first-child input[type='checkbox']");
                        checkCheckboxRobust(checkboxLocator);

                        selectedRows.put(rowKey, true);
                        selectedCount++;
                        System.out.println("Selected row: " + rowKey);
                    } catch (Exception e) {
                        System.out.println("Failed to select checkbox: " + e.getMessage());
                    }
                }
            }
        }


        if (selectedCount == 0) {
            System.out.println("No UNPROCESSED entries found for type: " + type);
            return;
        }

        System.out.println("Total selected entries: " + selectedCount);

        Thread.sleep(2000); // Wait for UI to update

        try {
            By ingestButtonLocator = By.name(buttonName);

            // Uso do clique robusto
            clickElementRobust(ingestButtonLocator);

            try {
                wait.until(ExpectedConditions.alertIsPresent());
                Alert alert = driver.switchTo().alert();
                System.out.println("Ingest confirmation: " + alert.getText());
                alert.accept();
            } catch (TimeoutException e) {
                System.out.println("No confirm dialog appeared.");
            }
        } catch (TimeoutException | NoSuchElementException e) {
            fail("Ingest button with name '" + buttonName + "' not found or not clickable.");
        }


        Thread.sleep(2000);
        // Retry check loop
        int attempts = 0;
        int processedCount = 0;

        while (attempts < MAX_ATTEMPTS) {
            Thread.sleep(WAIT_INTERVAL_MS);
            driver.navigate().refresh();
            wait.until(ExpectedConditions.visibilityOfElementLocated(By.tagName("table")));

            List<WebElement> updatedRows = driver.findElements(By.xpath("//table//tbody//tr"));
            processedCount = 0;

            for (WebElement row : updatedRows) {
                List<WebElement> cells = row.findElements(By.tagName("td"));
                if (cells.size() >= 5) {
                    String rowKey = cells.get(1).getText().trim();
                    String newStatus = cells.get(4).getText().trim();

                    if (selectedRows.containsKey(rowKey) && "PROCESSED".equalsIgnoreCase(newStatus)) {
                        processedCount++;
                    }
                }
            }

            System.out.println("Attempt " + (attempts + 1) + ": Processed " + processedCount + " of " + selectedCount);

            if (processedCount == selectedCount) {
                break;
            }

            attempts++;
        }

        assertEquals(selectedCount, processedCount,
                "Not all selected entries were processed.");
    }
    protected void ingestSpecificINS(String fileName) throws InterruptedException {
        String type = "ins";
        driver.get("http://" + ip + "/rep/select/mt/" + type + "/table/1/9/none");

        wait.until(ExpectedConditions.visibilityOfElementLocated(By.id("edit-element-table")));

        List<WebElement> rows = driver.findElements(By.xpath("//table[@id='edit-element-table']//tbody//tr"));
        int selectedCount = 0;
        selectedRows.clear();

        System.out.println("Procurando pelo FileName: " + fileName);
        System.out.println("Total de linhas na tabela: " + rows.size());


        for (WebElement row : rows) {
            List<WebElement> cells = row.findElements(By.tagName("td"));
            if (cells.size() >= 7) {
                String status = cells.get(1).getText().trim();       // Coluna Status
                String currentFileName = cells.get(3).getText().trim(); // Coluna FileName

                System.out.println("Linha: FileName=" + currentFileName + ", Status=" + status);
                Thread.sleep(1000);

                if (fileName.equals(currentFileName) && "UNPROCESSED".equalsIgnoreCase(status)) {
                    System.out.println("Arquivo encontrado: " + fileName + " com status UNPROCESSED");
                    try {
                        System.out.println("Selecionando checkbox para o arquivo: " + fileName);
                        String checkboxName = "element_table[" + fileName + "]";
                        System.out.println("Selecionando checkbox: " + checkboxName);

                        By checkboxLocator = By.name(checkboxName);
                        checkCheckboxRobust(checkboxLocator);

                        selectedRows.put(fileName, true);
                        selectedCount++;
                        System.out.println("Selecionado para ingestão: " + fileName);
                        Thread.sleep(1000);
                        break;

                    } catch (Exception e) {
                        System.out.println("Erro ao selecionar checkbox para '" + fileName + "': " + e.getMessage());
                    }
                }
            }
        }

        if (selectedCount == 0) {
            fail("Arquivo '" + fileName + "' com status UNPROCESSED não encontrado.");
        }

        By ingestButtonLocator = By.name(buttonName);
        try {
            clickElementRobust(ingestButtonLocator);
            System.out.println("Botão de ingestão clicado.");

            WebDriverWait waitAlert = new WebDriverWait(driver, Duration.ofSeconds(20));
            try {
                waitAlert.until(ExpectedConditions.alertIsPresent());
                Alert alert = driver.switchTo().alert();
                System.out.println("Confirmação do ingest: " + alert.getText());
                alert.accept();
                Thread.sleep(1000);
            } catch (TimeoutException e) {
                System.out.println("Nenhuma confirmação apareceu.");
            }

        } catch (Exception e) {
            fail("Erro ao clicar no botão de ingest: " + e.getMessage());
        }

        Thread.sleep(2000);

        // Retry para verificar se foi processado
        int attempts = 0;
        int processedCount = 0;

        while (attempts < MAX_ATTEMPTS) {
            Thread.sleep(WAIT_INTERVAL_MS);
            driver.navigate().refresh();
            wait.until(ExpectedConditions.visibilityOfElementLocated(By.id("edit-element-table")));

            List<WebElement> updatedRows = driver.findElements(By.xpath("//table[@id='edit-element-table']//tbody//tr"));
            processedCount = 0;

            for (WebElement row : updatedRows) {
                List<WebElement> cells = row.findElements(By.tagName("td"));
                if (cells.size() >= 7) {
                    String updatedFileName = cells.get(3).getText().trim(); // Coluna FileName
                    String newStatus = cells.get(1).getText().trim();       // Coluna Status

                    if (fileName.equals(updatedFileName) && "PROCESSED".equalsIgnoreCase(newStatus)) {
                        processedCount++;
                    }
                }
            }

            System.out.println("Tentativa " + (attempts + 1) + ": Arquivos processados = " + processedCount);

            if (processedCount == selectedCount) {
                break;
            }

            attempts++;
        }

        assertEquals(selectedCount, processedCount,
            "O arquivo '" + fileName + "' não foi processado corretamente.");
    }








    protected void ingestSpecificSDD(String fileName) throws InterruptedException {
        String type = "sdd";
        driver.get("http://" + ip + "/rep/select/mt/" + type + "/table/1/9/none");

        wait.until(ExpectedConditions.visibilityOfElementLocated(By.id("edit-element-table")));

        WebElement table = driver.findElement(By.id("edit-element-table"));
        List<WebElement> rows = table.findElements(By.tagName("tr"));

        int selectedCount = 0;
        selectedRows.clear();

        for (WebElement row : rows) {
            List<WebElement> cells = row.findElements(By.tagName("td"));
            if (cells.size() >= 5) {
                String name = cells.get(2).getText().trim(); // coluna 2 é "Name"
                String status = cells.get(4).getText().replaceAll("\\<.*?\\>", "").trim();

                if (name.equalsIgnoreCase(fileName) && status.equalsIgnoreCase("UNPROCESSED")) {
                    try {
                        String checkboxId = "checkbox_" + name;  // ajuste para o id correto do checkbox
                        checkCheckboxRobust(By.id(checkboxId));

                        selectedRows.put(name, true);
                        selectedCount++;
                        System.out.println("Selected file: " + name);
                        break; // seleciona apenas um arquivo
                    } catch (Exception e) {
                        fail("Could not click checkbox for file: " + name + ". Error: " + e.getMessage());
                    }
                }
            }
        }

        if (selectedCount == 0) {
            fail("File '" + fileName + "' not found with UNPROCESSED status.");
        }

        By ingestButtonLocator = By.name(buttonName); // mantive o By.name pois geralmente botão não tem id fixo
        try {
            clickElementRobust(ingestButtonLocator);

            try {
                wait.until(ExpectedConditions.alertIsPresent());
                Alert alert = driver.switchTo().alert();
                System.out.println("Ingest confirmation: " + alert.getText());
                alert.accept();
            } catch (TimeoutException e) {
                System.out.println("No confirm dialog appeared.");
            }

        } catch (Exception e) {
            fail("Ingest button with name '" + buttonName + "' not found or not clickable: " + e.getMessage());
        }

        int attempts = 0;
        while (attempts < MAX_ATTEMPTS) {
            Thread.sleep(WAIT_INTERVAL_MS);
            driver.navigate().refresh();
            wait.until(ExpectedConditions.visibilityOfElementLocated(By.id("edit-element-table")));

            WebElement updatedTable = driver.findElement(By.id("edit-element-table"));
            List<WebElement> updatedRows = updatedTable.findElements(By.tagName("tr"));
            boolean processed = false;

            for (WebElement row : updatedRows) {
                List<WebElement> cells = row.findElements(By.tagName("td"));
                if (cells.size() >= 5) {
                    String name = cells.get(2).getText().trim();
                    String newStatus = cells.get(4).getText().replaceAll("\\<.*?\\>", "").trim();

                    if (name.equalsIgnoreCase(fileName) && newStatus.equalsIgnoreCase("PROCESSED")) {
                        processed = true;
                        break;
                    }
                }
            }

            if (processed) {
                System.out.println("File '" + fileName + "' was successfully processed.");
                return;
            }

            attempts++;
            System.out.println("Attempt " + attempts + " - still waiting...");
        }

        fail("File '" + fileName + "' was not processed after " + MAX_ATTEMPTS + " attempts.");
    }

    private void checkCheckboxRobust(By fallbackLocator) throws InterruptedException {
        int maxAttempts = 5;
        int attempt = 0;

        while (attempt < maxAttempts) {
            attempt++;
            try {
                Thread.sleep(1000);

                // Detectar se é By.id ou By.name
                String locatorString = fallbackLocator.toString();

                if (locatorString.startsWith("By.id: ")) {
                    // Extrair o id completo para busca por fragmento
                    String idFragment = locatorString.replace("By.id: ", "").trim();

                    List<WebElement> checkboxes = driver.findElements(By.cssSelector("input[type='checkbox']"));

                    for (WebElement checkbox : checkboxes) {
                        String id = checkbox.getAttribute("id");

                        if (id != null && id.contains(idFragment)) {
                            System.out.println("Matching checkbox found with ID: " + id);

                            Boolean isSelected = (Boolean) ((JavascriptExecutor) driver)
                                    .executeScript("return arguments[0].checked;", checkbox);

                            if (isSelected == null || !isSelected) {
                                System.out.println("Checkbox not selected, clicking via JS");
                                ((JavascriptExecutor) driver).executeScript("arguments[0].click();", checkbox);
                                Thread.sleep(1000);
                            } else {
                                System.out.println("Checkbox already selected");
                            }

                            return;
                        }
                    }

                    System.out.println("No checkbox containing fragment '" + idFragment + "' found on attempt " + attempt);

                } else if (locatorString.startsWith("By.name: ")) {
                    // Extrair o name completo para busca direta
                    String nameValue = locatorString.replace("By.name: ", "").trim();

                    // Busca checkbox pelo name
                    List<WebElement> checkboxes = driver.findElements(By.cssSelector("input[type='checkbox'][name='" + nameValue + "']"));

                    if (checkboxes.isEmpty()) {
                        System.out.println("No checkbox found with name '" + nameValue + "' on attempt " + attempt);
                    } else {
                        WebElement checkbox = checkboxes.get(0);
                        System.out.println("Matching checkbox found with name: " + nameValue);

                        Boolean isSelected = (Boolean) ((JavascriptExecutor) driver)
                                .executeScript("return arguments[0].checked;", checkbox);

                        if (isSelected == null || !isSelected) {
                            System.out.println("Checkbox not selected, clicking via JS");
                            ((JavascriptExecutor) driver).executeScript("arguments[0].click();", checkbox);
                            Thread.sleep(1000);
                        } else {
                            System.out.println("Checkbox already selected");
                        }

                        return;
                    }

                } else {
                    // Para outros tipos de By, tentar localizar direto
                    WebElement checkbox = driver.findElement(fallbackLocator);

                    Boolean isSelected = (Boolean) ((JavascriptExecutor) driver)
                            .executeScript("return arguments[0].checked;", checkbox);

                    if (isSelected == null || !isSelected) {
                        System.out.println("Checkbox not selected, clicking via JS");
                        ((JavascriptExecutor) driver).executeScript("arguments[0].click();", checkbox);
                        Thread.sleep(1000);
                    } else {
                        System.out.println("Checkbox already selected");
                    }

                    return;
                }

            } catch (Exception e) {
                System.out.println("Unexpected error on attempt " + attempt + ": " + e.getMessage());
            }

            Thread.sleep(1000);
        }

        throw new RuntimeException("Failed to find and select checkbox using locator: " + fallbackLocator.toString());
    }






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
    public void setIngestMode(String ingestMode) {
        this.ingestMode = ingestMode;
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
