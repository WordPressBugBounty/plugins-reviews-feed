import AddApiKeyFreeRetrieverModal from "./AddSourceModalSections/AddApiKeyFreeRetrieverModal";
import SourceAddedModal from "./AddSourceModalSections/SourceAddedModal";
import SourceLimitExceededModal from "./AddSourceModalSections/SourceLimitExceededModal";
import VerifyEmailModal from "./AddSourceModalSections/VerifyEmailModal";

const FreeRetrieverModal = (props) => {

	return (
		<>
			{
				props?.screenType === 'verifyEmail' &&
				<VerifyEmailModal
					onCancel={() => {
						props.onCancel()
					}}
				/>
			}
			{
				props?.screenType === 'sourceAdded' &&
				<SourceAddedModal
					onCancel={() => {
						props.onCancel()
					}}
				/>
			}
			{
				props?.screenType === 'limitExceeded' &&
				<SourceLimitExceededModal
					onCancel={() => {
						props.onCancel()
					}}
				/>
			}
			{
				props?.screenType === 'addApiKey' &&
				<AddApiKeyFreeRetrieverModal
					onSuccessApiKey={(provider) => {
						props?.onSuccessApiKey(provider)
					}}
					onCancel={() => {
						props.onCancel()
					}}
				/>
			}
		</>

	)

}

export default FreeRetrieverModal;