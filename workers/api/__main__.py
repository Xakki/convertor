from workers.api.worker import run
from workers.common.logging_config import configure_logging


if __name__ == "__main__":
    configure_logging()
    run()
