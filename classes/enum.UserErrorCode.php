<?php

enum UserErrorCode: int
{
	case ERROR_USER_ID_EMPTY = -1;
	case ERROR_MULTIPLE_USERS = -2;
	case ERROR_USER_NOT_FOUND = -3;
}
