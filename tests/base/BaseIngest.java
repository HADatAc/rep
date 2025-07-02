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
    String ip = "54.75.120.47";

    @BeforeAll
    void setup() throws InterruptedException {
        ChromeOptions options = new ChromeOptions();

        options.setBinary("/usr/bin/chromium-browser");
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
        logCurrentPageState(1000);
        wait.until(ExpectedConditions.visibilityOfElementLocated(By.id("edit-name")));
        wait.until(ExpectedConditions.elementToBeClickable(By.id("edit-submit")));

        driver.findElement(By.id("edit-name")).sendKeys("admin");
        driver.findElement(By.id("edit-pass")).sendKeys("admin");

        clickElementRobust(By.id("edit-submit"));

        wait.until(ExpectedConditions.visibilityOfElementLocated(By.cssSelector("#toolbar-item-user")));
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
                        clickElementRobust(checkboxLocator);

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
        driver.get("http://localhost/rep/select/mt/" + type + "/table/1/9/none");

        wait.until(ExpectedConditions.visibilityOfElementLocated(By.id("edit-element-table")));

        List<WebElement> rows = driver.findElements(By.xpath("//table[@id='edit-element-table']//tbody//tr"));
        int selectedCount = 0;
        selectedRows.clear();

        for (int i = 0; i < rows.size(); i++) {
            List<WebElement> cells = rows.get(i).findElements(By.tagName("td"));
            if (cells.size() >= 5) {
                String status = cells.get(4).getText().trim();
                String rowKey = cells.get(1).getText().trim();

                if ("UNPROCESSED".equalsIgnoreCase(status)) {
                    try {
                        String checkboxId = "checkbox_" + rowKey;  // ajuste conforme seu id real
                        By checkboxLocator = By.id(checkboxId);

                        clickElementRobust(checkboxLocator);

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
            fail("File '" + fileName + "' not found with UNPROCESSED status.");
        }

        By ingestButtonLocator = By.name(buttonName);
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

            boolean processed = false;
            List<WebElement> updatedRows = driver.findElements(By.xpath("//table[@id='edit-element-table']//tbody//tr"));
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
                        clickElementRobust(By.id(checkboxId));

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



    public String getIngestMode() {
        return ingestMode;
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
